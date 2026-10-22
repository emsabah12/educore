<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Leave\LeaveSettingsOverview;
use Modules\HR\Application\Leave\ManageLeaveTypes;
use Modules\HR\Application\Leave\ManageWorkHolidays;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\Holiday;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Halaman Pengaturan cuti (PRD-001 §13.2, HR-3a): jenis cuti, libur mingguan per jenjang/lembaga,
 * hari libur yayasan. Butuh `hr.leave.manage` di tingkat Yayasan (Admin SDM, Admin Yayasan);
 * tanpa izin 403.
 */
final class LeaveSettingsController
{
    private const CODE_RULE = 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,29}$/';

    private const DATE_RULE = 'date_format:Y-m-d';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, LeaveSettingsOverview $overview): Response
    {
        $this->authorize();

        $thisYear = (int) BusinessClock::today()->format('Y');
        $requested = $request->integer('tahun', $thisYear);
        $year = $requested >= 2000 && $requested <= 2100 ? $requested : $thisYear;

        return Inertia::render('hr/leave-settings', $overview->build($this->tenantId(), $year) + [
            'year' => $year,
            'limits' => ['maxDays' => ManageLeaveTypes::MAX_DAYS],
        ]);
    }

    // ── Jenis cuti ───────────────────────────────────────────────────────────

    public function storeType(Request $request, ManageLeaveTypes $types): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'code' => ['required', 'string', self::CODE_RULE],
            'name' => ['required', 'string', 'max:'.ManageLeaveTypes::NAME_MAX_LENGTH],
            'has_quota' => ['nullable', 'boolean'],
            'annual_quota' => ['nullable', 'integer', 'between:1,'.ManageLeaveTypes::MAX_DAYS],
            'max_days' => ['nullable', 'integer', 'between:1,'.ManageLeaveTypes::MAX_DAYS],
        ], ['code.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.'], $this->typeAttributes());

        $type = $this->attempt(fn (): LeaveType => $types->create(
            $this->tenantId(),
            $request->string('code')->value(),
            $request->string('name')->value(),
            $request->boolean('has_quota'),
            $this->optionalInt($request, 'annual_quota'),
            $this->optionalInt($request, 'max_days'),
        ));

        return $this->done("Jenis cuti {$type->name} ditambahkan.");
    }

    public function updateType(Request $request, string $type, ManageLeaveTypes $types): RedirectResponse
    {
        $this->authorize();
        $target = $this->findType($type);

        $request->validate([
            'name' => ['required', 'string', 'max:'.ManageLeaveTypes::NAME_MAX_LENGTH],
            'annual_quota' => ['nullable', 'integer', 'between:1,'.ManageLeaveTypes::MAX_DAYS],
            'max_days' => ['nullable', 'integer', 'between:1,'.ManageLeaveTypes::MAX_DAYS],
        ], [], $this->typeAttributes());

        $changed = $this->attempt(fn (): bool => $types->update(
            $this->tenantId(),
            $target->id,
            $request->string('name')->value(),
            $this->optionalInt($request, 'annual_quota'),
            $this->optionalInt($request, 'max_days'),
        ));

        return $this->done($changed ? 'Jenis cuti disimpan.' : 'Tidak ada data yang berubah.');
    }

    public function deactivateType(string $type, ManageLeaveTypes $types): RedirectResponse
    {
        $this->authorize();
        $target = $this->findType($type);

        $this->attempt(fn (): LeaveType => $types->setActive($this->tenantId(), $target->id, false));

        return $this->done("{$target->name} dinonaktifkan: tidak bisa dipilih untuk pengajuan baru, riwayatnya tetap tersimpan.");
    }

    public function activateType(string $type, ManageLeaveTypes $types): RedirectResponse
    {
        $this->authorize();
        $target = $this->findType($type);

        $this->attempt(fn (): LeaveType => $types->setActive($this->tenantId(), $target->id, true));

        return $this->done("{$target->name} diaktifkan kembali.");
    }

    // ── Libur mingguan ───────────────────────────────────────────────────────

    public function assignWeeklyOff(Request $request, LeaveSettingsOverview $overview): RedirectResponse
    {
        $this->authorize();

        $validated = $request->validate([
            'target' => ['required', Rule::in(['jenjang', 'lembaga'])],
            'jenjang' => ['nullable', 'required_if:target,jenjang', Rule::enum(Jenjang::class)],
            'organization_id' => ['nullable', 'required_if:target,lembaga', 'uuid'],
            'weekly_off_days' => ['nullable', 'array', 'max:7'],
            'weekly_off_days.*' => ['integer', 'between:1,7'],
            'enforced' => ['nullable', 'boolean'],
        ], [
            'jenjang.required_if' => 'Pilih jenjang.',
            'organization_id.required_if' => 'Pilih lembaga atau unit.',
            'weekly_off_days.*.integer' => 'Pilih hari dari daftar.',
            'weekly_off_days.*.between' => 'Pilih hari dari daftar.',
        ], ['jenjang' => 'jenjang', 'organization_id' => 'lembaga', 'weekly_off_days' => 'libur mingguan']);

        $days = array_values(array_map('intval', (array) ($validated['weekly_off_days'] ?? [])));
        $byJenjang = $validated['target'] === 'jenjang';

        try {
            $overview->assignWeeklyOff(
                $byJenjang ? null : (string) $validated['organization_id'],
                $byJenjang ? Jenjang::from((string) $validated['jenjang']) : null,
                $days,
                $request->boolean('enforced'),
            );
        } catch (SettingException $exception) {
            throw ValidationException::withMessages(['weekly_off_days' => $exception->getMessage()]);
        }

        return $this->done('Libur mingguan disimpan.');
    }

    // ── Hari libur ───────────────────────────────────────────────────────────

    public function storeHoliday(Request $request, ManageWorkHolidays $holidays): RedirectResponse
    {
        $this->authorize();
        [$name, $start, $end] = $this->holidayInput($request);

        $holiday = $this->attempt(fn (): Holiday => $holidays->add($this->tenantId(), $name, $start, $end));

        return $this->done("Hari libur {$holiday->name} ditambahkan.");
    }

    public function updateHoliday(Request $request, string $holiday, ManageWorkHolidays $holidays): RedirectResponse
    {
        $this->authorize();
        $target = $this->findHoliday($holiday);
        [$name, $start, $end] = $this->holidayInput($request);

        $changed = $this->attempt(fn (): bool => $holidays->update($this->tenantId(), $target->id, $name, $start, $end));

        return $this->done($changed ? 'Hari libur disimpan.' : 'Tidak ada data yang berubah.');
    }

    public function destroyHoliday(string $holiday, ManageWorkHolidays $holidays): RedirectResponse
    {
        $this->authorize();
        $target = $this->findHoliday($holiday);

        $name = $this->attempt(fn (): string => $holidays->remove($this->tenantId(), $target->id));

        return $this->done("Hari libur {$name} dihapus.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Pengaturan cuti berlaku untuk seluruh yayasan: 403 bila tidak punya izin di tingkat Yayasan.
     */
    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::LEAVE_MANAGE, null, null)->authorize();
    }

    private function findType(string $id): LeaveType
    {
        $type = Str::isUuid($id) ? LeaveType::query()->find($id) : null;

        if (! $type instanceof LeaveType) {
            abort(404);
        }

        return $type;
    }

    private function findHoliday(string $id): Holiday
    {
        $holiday = Str::isUuid($id) ? Holiday::query()->find($id) : null;

        if (! $holiday instanceof Holiday) {
            abort(404);
        }

        return $holiday;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function holidayInput(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:'.ManageWorkHolidays::NAME_MAX_LENGTH],
            'start_date' => ['required', self::DATE_RULE],
            'end_date' => ['required', self::DATE_RULE],
        ], [], ['name' => 'nama hari libur', 'start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai']);

        return [$request->string('name')->value(), $request->string('start_date')->value(), $request->string('end_date')->value()];
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * @return array<string, string>
     */
    private function typeAttributes(): array
    {
        return ['code' => 'kode', 'name' => 'nama', 'annual_quota' => 'jatah per tahun', 'max_days' => 'batas hari per pengajuan'];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (LeaveException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
