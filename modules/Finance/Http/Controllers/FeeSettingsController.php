<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Settings\ScopedSettingOverview;
use Modules\Core\Application\Settings\SetScopedSetting;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Cash\ExpenseApprovalLimitSetting;
use Modules\Finance\Application\Cash\ManageCashAccounts;
use Modules\Finance\Application\Fee\FeeSettingsOverview;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Invoice\DueDaySetting;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Fee\FeeKind;
use Modules\Finance\Domain\Fee\FeeType;

/**
 * Pengaturan keuangan (PRD-003 §6, FN-1a): jenis biaya dan tarif per lembaga × tahun ajaran (opsional
 * per tingkat). Hanya pemilik `finance.settings.manage` di tingkat Yayasan (Admin Keuangan, Admin
 * Yayasan); selain itu 403.
 */
final class FeeSettingsController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, FeeSettingsOverview $overview, ScopedSettingOverview $settings): Response
    {
        $this->authorize();
        $tenantId = $this->tenantId();

        $organizations = $overview->organizations($tenantId);
        $organizationId = $this->pick(array_column($organizations, 'value'), $request->query('lembaga'));
        $years = $organizationId === null ? [] : $overview->years($tenantId, $organizationId);
        $current = null;

        foreach ($years as $year) {
            if ($year['is_current']) {
                $current = $year['value'];
            }
        }

        $yearId = $this->pick(array_column($years, 'value'), $request->query('tahun'), $current);

        return Inertia::render('finance/settings', [
            'types' => $overview->types($tenantId),
            'kinds' => array_map(fn (FeeKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], FeeKind::cases()),
            'organizations' => $organizations,
            'organizationId' => $organizationId,
            'years' => array_map(fn (array $year): array => ['value' => $year['value'], 'label' => $year['label']], $years),
            'yearId' => $yearId,
            'rates' => $organizationId === null || $yearId === null ? [] : $overview->rates($tenantId, $organizationId, $yearId),
            // FN-2a: jatuh tempo tagihan bulanan per jenjang/lembaga (aturan berjenjang).
            'dueDay' => [
                'rules' => $settings->rules($tenantId, DueDaySetting::KEY, fn (mixed $value): string => DueDaySetting::label(DueDaySetting::normalize($value))),
                'usage' => $settings->usage($tenantId, DueDaySetting::KEY, fn (mixed $value): string => DueDaySetting::label(DueDaySetting::normalize($value))),
            ],
            // KS-5 (PRD-004 §16): batas persetujuan pengeluaran berjenjang.
            'approvalLimit' => [
                'rules' => $settings->rules($tenantId, ExpenseApprovalLimitSetting::KEY, fn (mixed $value): string => ExpenseApprovalLimitSetting::label(ExpenseApprovalLimitSetting::normalize($value))),
                'usage' => $settings->usage($tenantId, ExpenseApprovalLimitSetting::KEY, fn (mixed $value): string => ExpenseApprovalLimitSetting::label(ExpenseApprovalLimitSetting::normalize($value))),
            ],
            'targets' => $settings->targetOptions($tenantId),
        ]);
    }

    public function store(Request $request, ManageFeeTypes $types): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:'.ManageFeeTypes::NAME_MAX_LENGTH],
            'kind' => ['required', Rule::enum(FeeKind::class)],
        ], ['kind.required' => 'Pilih sifat biaya.'], ['code' => 'kode', 'name' => 'nama', 'kind' => 'sifat']);

        $type = $this->attempt(fn (): FeeType => $types->create(
            $this->tenantId(),
            $request->string('code')->value(),
            $request->string('name')->value(),
            FeeKind::from($request->string('kind')->value()),
        ));

        return $this->done("{$type->name} ditambahkan.");
    }

    public function update(Request $request, string $type, ManageFeeTypes $types): RedirectResponse
    {
        $this->authorize();

        $request->validate(['name' => ['required', 'string', 'max:'.ManageFeeTypes::NAME_MAX_LENGTH]], [], ['name' => 'nama']);

        $renamed = $this->attempt(fn (): FeeType => $types->rename($this->tenantId(), $type, $request->string('name')->value()));

        return $this->done("{$renamed->name} disimpan.");
    }

    public function deactivate(string $type, ManageFeeTypes $types): RedirectResponse
    {
        $this->authorize();
        $item = $this->attempt(fn (): FeeType => $types->setActive($this->tenantId(), $type, false));

        return $this->done("{$item->name} dinonaktifkan. Tarif yang ada tetap tersimpan, tetapi tidak ditagihkan lagi.");
    }

    public function activate(string $type, ManageFeeTypes $types): RedirectResponse
    {
        $this->authorize();
        $item = $this->attempt(fn (): FeeType => $types->setActive($this->tenantId(), $type, true));

        return $this->done("{$item->name} diaktifkan kembali.");
    }

    /**
     * Simpan tarif (tambah, atau ubah nominal bila kombinasi jenis × lembaga × tahun × tingkat sudah ada).
     */
    public function setRate(Request $request, string $type, ManageFeeRates $rates): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'uuid'],
            'grade' => ['nullable', 'integer', 'between:1,12'],
            'amount' => ['required', 'string', 'max:30'],
        ], [
            'organization_id.required' => 'Pilih lembaga.',
            'academic_year_id.required' => 'Pilih tahun ajaran.',
            'grade.between' => 'Tingkat harus angka 1–12, atau kosong untuk semua tingkat.',
            'grade.integer' => 'Tingkat harus angka 1–12, atau kosong untuk semua tingkat.',
        ], ['amount' => 'nominal']);

        $created = $this->attempt(fn (): bool => $rates->set(
            $this->tenantId(),
            $type,
            $request->string('organization_id')->value(),
            $request->string('academic_year_id')->value(),
            $request->filled('grade') ? $request->integer('grade') : null,
            $request->input('amount'),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done($created ? 'Tarif ditambahkan.' : 'Tarif disimpan.');
    }

    public function removeRate(string $rate, ManageFeeRates $rates): RedirectResponse
    {
        $this->authorize();
        $this->attempt(function () use ($rates, $rate): bool {
            $rates->remove($this->tenantId(), $rate);

            return true;
        });

        return $this->done('Tarif dihapus.');
    }

    /**
     * Jatuh tempo tagihan bulanan (FN-2a): tanggal 1–28, se-yayasan, per jenjang, atau per lembaga/unit.
     */
    /**
     * Batas persetujuan pengeluaran per Yayasan/jenjang/lembaga (KS-5). 0 = tanpa batas.
     */
    public function setApprovalLimit(Request $request, SetScopedSetting $settings): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'target' => ['required', Rule::in(['yayasan', 'jenjang', 'lembaga'])],
            'jenjang' => ['nullable', 'required_if:target,jenjang', Rule::enum(Jenjang::class)],
            'organization_id' => ['nullable', 'required_if:target,lembaga', 'uuid'],
            'enforced' => ['nullable', 'boolean'],
        ], [
            'jenjang.required_if' => 'Pilih jenjang.',
            'organization_id.required_if' => 'Pilih lembaga atau unit.',
        ]);

        $limit = $this->attempt(fn (): int => Rupiah::parse($request->input('limit'), 'limit', 'batas', 0, ManageCashAccounts::MAX_AMOUNT));

        [$organizationId, $jenjang] = match ($request->string('target')->value()) {
            'jenjang' => [null, Jenjang::from($request->string('jenjang')->value())],
            'lembaga' => [$request->string('organization_id')->value(), null],
            default => [null, null],
        };

        try {
            $settings->handle(ExpenseApprovalLimitSetting::KEY, $organizationId, $jenjang, $limit, $request->boolean('enforced'));
        } catch (SettingException $exception) {
            throw ValidationException::withMessages(['limit' => $exception->getMessage()]);
        }

        return $this->done('Batas persetujuan disimpan. Berlaku untuk persetujuan berikutnya.');
    }

    public function setDueDay(Request $request, SetScopedSetting $settings): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'target' => ['required', Rule::in(['yayasan', 'jenjang', 'lembaga'])],
            'jenjang' => ['nullable', 'required_if:target,jenjang', Rule::enum(Jenjang::class)],
            'organization_id' => ['nullable', 'required_if:target,lembaga', 'uuid'],
            'enforced' => ['nullable', 'boolean'],
            'day' => ['required', 'integer', 'between:1,28'],
        ], [
            'jenjang.required_if' => 'Pilih jenjang.',
            'organization_id.required_if' => 'Pilih lembaga atau unit.',
            'day.between' => 'Tanggal jatuh tempo 1–28 (selalu ada di semua bulan).',
        ], ['day' => 'tanggal jatuh tempo']);

        [$organizationId, $jenjang] = match ($request->string('target')->value()) {
            'jenjang' => [null, Jenjang::from($request->string('jenjang')->value())],
            'lembaga' => [$request->string('organization_id')->value(), null],
            default => [null, null],
        };

        try {
            $settings->handle(DueDaySetting::KEY, $organizationId, $jenjang, $request->integer('day'), $request->boolean('enforced'));
        } catch (SettingException $exception) {
            throw ValidationException::withMessages(['day' => $exception->getMessage()]);
        }

        return $this->done('Jatuh tempo disimpan. Berlaku untuk tagihan yang dibuat sesudah ini.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $allowed
     */
    private function pick(array $allowed, mixed $requested, ?string $fallback = null): ?string
    {
        if (is_string($requested) && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $fallback ?? ($allowed[0] ?? null);
    }

    private function authorize(): void
    {
        $this->authorization->inspectTarget(FinanceAccess::SETTINGS_MANAGE, null, null)->authorize();
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
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
        } catch (FinanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
