<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Settings\SetScopedSetting;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Leave\LeaveSettingsOverview;
use Modules\HR\Application\Payroll\AttendanceDeductionSetting;
use Modules\HR\Application\Payroll\EmployeePay;
use Modules\HR\Application\Payroll\ManagePayComponents;
use Modules\HR\Application\Payroll\ManagePositionRates;
use Modules\HR\Application\Payroll\TeachingPaySetting;
use Modules\HR\Domain\Catalog\Position;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;

/**
 * Pengaturan gaji (PRD-001 §16, HR-5): komponen gaji (HR-5a), tarif jabatan (HR-5a-2), honor mengajar
 * & potongan presensi per jenjang/lembaga (HR-5b). Hanya pemilik `hr.payroll.manage` di
 * tingkat Yayasan (Admin SDM, Admin Yayasan); selain itu 403.
 */
final class PayrollSettingsController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(LeaveSettingsOverview $overview): Response
    {
        $this->authorize();

        $components = PayComponent::query()
            ->orderByDesc('is_active')
            ->orderByRaw("CASE kind WHEN 'PENDAPATAN' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (PayComponent $component): array => [
                'id' => $component->id,
                'code' => $component->code,
                'name' => $component->name,
                'kind' => $component->kind->value,
                'kind_label' => $component->kind->label(),
                'basis' => $component->basis->value,
                'basis_label' => $component->basis->label(),
                'is_active' => $component->is_active,
                'employees' => 0,
                'rates' => [],
            ])
            ->values()
            ->all();

        $tenantId = $this->tenantContext->require()->tenantId;
        $used = DB::table('hr_employee_pay_components')
            ->where('tenant_id', $tenantId)
            ->selectRaw('pay_component_id, COUNT(*) AS total')
            ->groupBy('pay_component_id')
            ->pluck('total', 'pay_component_id')
            ->all();

        $rates = [];

        foreach (DB::table('hr_pay_position_rates as r')
            ->join('hr_positions as po', 'po.id', '=', 'r.position_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.tenant_id', $tenantId)
            ->orderBy('po.name')
            ->orderByRaw('o.name NULLS FIRST')
            ->get(['r.id', 'r.pay_component_id', 'r.position_id', 'r.organization_id', 'r.amount', 'po.name as position', 'o.name as organization']) as $rate) {
            $rates[(string) $rate->pay_component_id][] = [
                'id' => (string) $rate->id,
                'position_id' => (string) $rate->position_id,
                'organization_id' => $rate->organization_id === null ? null : (string) $rate->organization_id,
                'position' => (string) $rate->position,
                'organization' => $rate->organization === null ? null : (string) $rate->organization,
                'amount' => (int) $rate->amount,
            ];
        }

        foreach ($components as $index => $component) {
            $components[$index]['employees'] = (int) ($used[$component['id']] ?? 0);
            $components[$index]['rates'] = $rates[$component['id']] ?? [];
        }

        return Inertia::render('hr/payroll/settings', [
            'components' => $components,
            'kinds' => array_map(fn (PayComponentKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], PayComponentKind::cases()),
            'bases' => array_map(fn (PayComponentBasis $basis): array => ['value' => $basis->value, 'label' => $basis->label()], PayComponentBasis::cases()),
            'positions' => Position::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Position $position): array => ['value' => $position->id, 'label' => $position->name])->values()->all(),
            'organizations' => Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code')->get()
                ->map(fn (Organization $organization): array => ['value' => $organization->id, 'label' => "{$organization->name} ({$organization->code})"])->values()->all(),
            // HR-5b: honor mengajar & potongan presensi per jenjang/lembaga (aturan berjenjang).
            'teachingPay' => [
                'rules' => $overview->rules($tenantId, TeachingPaySetting::KEY, fn (mixed $value): string => TeachingPaySetting::label(TeachingPaySetting::normalize($value))),
                'usage' => $overview->usage($tenantId, TeachingPaySetting::KEY, fn (mixed $value): string => TeachingPaySetting::label(TeachingPaySetting::normalize($value))),
            ],
            'deduction' => [
                'rules' => $overview->rules($tenantId, AttendanceDeductionSetting::KEY, fn (mixed $value): string => AttendanceDeductionSetting::label(AttendanceDeductionSetting::normalize($value))),
                'usage' => $overview->usage($tenantId, AttendanceDeductionSetting::KEY, fn (mixed $value): string => AttendanceDeductionSetting::label(AttendanceDeductionSetting::normalize($value))),
            ],
            'targets' => $overview->targetOptions($tenantId),
        ]);
    }

    /**
     * Honor mengajar (HR-5b): cara hitung, menit per JP, tarif per JP — se-yayasan, per jenjang, atau per
     * lembaga/unit. Menyimpan aturan berjenjang butuh `core.settings.manage` (PRD-000 §8.3).
     */
    public function setTeachingPay(Request $request, SetScopedSetting $settings): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            ...$this->targetRules(),
            'mode' => ['required', Rule::in([TeachingPaySetting::MODE_FIXED, TeachingPaySetting::MODE_ACTUAL])],
            'minutes' => ['required', 'integer', 'between:10,120'],
            'rate' => ['required', 'string', 'max:30'],
        ], $this->targetMessages(), ['mode' => 'cara hitung', 'minutes' => 'menit per JP', 'rate' => 'tarif per JP']);

        $rate = $this->amount($request->input('rate'), 'rate', 'tarif per JP');
        [$organizationId, $jenjang] = $this->target($request);

        $this->saveSetting(fn () => $settings->handle(TeachingPaySetting::KEY, $organizationId, $jenjang, [
            'mode' => $request->string('mode')->value(),
            'minutes' => $request->integer('minutes'),
            'rate' => $rate,
        ], $request->boolean('enforced')), 'rate');

        return $this->done('Honor mengajar disimpan. Rekap bulan yang sudah dikunci tidak berubah.');
    }

    /**
     * Potongan presensi (HR-5b): rupiah per hari Alpa, per kali Terlambat, per kali Pulang cepat.
     */
    public function setDeduction(Request $request, SetScopedSetting $settings): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            ...$this->targetRules(),
            'absent' => ['required', 'string', 'max:30'],
            'late' => ['required', 'string', 'max:30'],
            'early' => ['required', 'string', 'max:30'],
        ], $this->targetMessages(), ['absent' => 'potongan alpa', 'late' => 'potongan terlambat', 'early' => 'potongan pulang cepat']);

        $value = [
            'absent' => $this->amount($request->input('absent'), 'absent', 'potongan alpa'),
            'late' => $this->amount($request->input('late'), 'late', 'potongan terlambat'),
            'early' => $this->amount($request->input('early'), 'early', 'potongan pulang cepat'),
        ];
        [$organizationId, $jenjang] = $this->target($request);

        $this->saveSetting(fn () => $settings->handle(AttendanceDeductionSetting::KEY, $organizationId, $jenjang, $value, $request->boolean('enforced')), 'early');

        return $this->done('Potongan presensi disimpan. Rekap bulan yang sudah dikunci tidak berubah.');
    }

    public function store(Request $request, ManagePayComponents $components): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:'.ManagePayComponents::NAME_MAX_LENGTH],
            'kind' => ['required', Rule::enum(PayComponentKind::class)],
            'basis' => ['required', Rule::enum(PayComponentBasis::class)],
        ], [], ['code' => 'kode', 'name' => 'nama', 'kind' => 'jenis', 'basis' => 'cara hitung']);

        $component = $this->attempt(fn (): PayComponent => $components->create(
            $this->tenantContext->require()->tenantId,
            $request->string('code')->value(),
            $request->string('name')->value(),
            PayComponentKind::from($request->string('kind')->value()),
            PayComponentBasis::from($request->string('basis')->value()),
        ));

        return $this->done("{$component->name} ditambahkan.");
    }

    public function update(Request $request, string $component, ManagePayComponents $components): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'name' => ['required', 'string', 'max:'.ManagePayComponents::NAME_MAX_LENGTH],
        ], [], ['name' => 'nama']);

        $renamed = $this->attempt(fn (): PayComponent => $components->rename($this->tenantContext->require()->tenantId, $component, $request->string('name')->value()));

        return $this->done("{$renamed->name} disimpan.");
    }

    public function deactivate(string $component, ManagePayComponents $components): RedirectResponse
    {
        $this->authorize();
        $item = $this->attempt(fn (): PayComponent => $components->setActive($this->tenantContext->require()->tenantId, $component, false));

        return $this->done("{$item->name} dinonaktifkan. Nominal pegawai tetap tersimpan, tetapi tidak ikut rekap baru.");
    }

    public function activate(string $component, ManagePayComponents $components): RedirectResponse
    {
        $this->authorize();
        $item = $this->attempt(fn (): PayComponent => $components->setActive($this->tenantContext->require()->tenantId, $component, true));

        return $this->done("{$item->name} diaktifkan kembali.");
    }

    /**
     * Simpan tarif jabatan (tambah, atau ubah bila jabatan × lembaga sudah ada) — HR-5a-2.
     */
    public function setRate(Request $request, string $component, ManagePositionRates $rates): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'position_id' => ['required', 'uuid'],
            'organization_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'string', 'max:30'],
        ], ['position_id.required' => 'Pilih jabatan.'], ['amount' => 'nominal']);

        try {
            $created = $rates->set(
                $this->tenantContext->require()->tenantId,
                $component,
                $request->string('position_id')->value(),
                $request->filled('organization_id') ? $request->string('organization_id')->value() : null,
                $request->input('amount'),
            );
        } catch (PayrollException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return $this->done($created ? 'Tarif jabatan ditambahkan.' : 'Tarif jabatan disimpan.');
    }

    public function removeRate(string $component, string $rate, ManagePositionRates $rates): RedirectResponse
    {
        $this->authorize();

        try {
            $rates->remove($this->tenantContext->require()->tenantId, $component, $rate);
        } catch (PayrollException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return $this->done('Tarif jabatan dihapus.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, list<mixed>>
     */
    private function targetRules(): array
    {
        return [
            'target' => ['required', Rule::in(['yayasan', 'jenjang', 'lembaga'])],
            'jenjang' => ['nullable', 'required_if:target,jenjang', Rule::enum(Jenjang::class)],
            'organization_id' => ['nullable', 'required_if:target,lembaga', 'uuid'],
            'enforced' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function targetMessages(): array
    {
        return [
            'jenjang.required_if' => 'Pilih jenjang.',
            'organization_id.required_if' => 'Pilih lembaga atau unit.',
        ];
    }

    /**
     * @return array{0: string|null, 1: Jenjang|null}
     */
    private function target(Request $request): array
    {
        return match ($request->string('target')->value()) {
            'jenjang' => [null, Jenjang::from($request->string('jenjang')->value())],
            'lembaga' => [$request->string('organization_id')->value(), null],
            default => [null, null],
        };
    }

    private function amount(mixed $value, string $field, string $label): int
    {
        try {
            return EmployeePay::parseAmount($value, $field, $label) ?? 0;
        } catch (PayrollException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    /**
     * @param  callable(): mixed  $save
     */
    private function saveSetting(callable $save, string $field): void
    {
        try {
            $save();
        } catch (SettingException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::PAYROLL_MANAGE, null, null)->authorize();
    }

    /**
     * @param  callable(): PayComponent  $action
     */
    private function attempt(callable $action): PayComponent
    {
        try {
            return $action();
        } catch (PayrollException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
