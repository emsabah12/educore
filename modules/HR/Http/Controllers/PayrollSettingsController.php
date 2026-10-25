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
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Payroll\ManagePayComponents;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;

/**
 * Pengaturan gaji (PRD-001 §16, HR-5): komponen gaji (HR-5a). Hanya pemilik `hr.payroll.manage` di
 * tingkat Yayasan (Admin SDM, Admin Yayasan); selain itu 403.
 */
final class PayrollSettingsController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(): Response
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
            ])
            ->values()
            ->all();

        $used = DB::table('hr_employee_pay_components')
            ->where('tenant_id', $this->tenantContext->require()->tenantId)
            ->selectRaw('pay_component_id, COUNT(*) AS total')
            ->groupBy('pay_component_id')
            ->pluck('total', 'pay_component_id')
            ->all();

        foreach ($components as $index => $component) {
            $components[$index]['employees'] = (int) ($used[$component['id']] ?? 0);
        }

        return Inertia::render('hr/payroll-settings', [
            'components' => $components,
            'kinds' => array_map(fn (PayComponentKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], PayComponentKind::cases()),
            'bases' => array_map(fn (PayComponentBasis $basis): array => ['value' => $basis->value, 'label' => $basis->label()], PayComponentBasis::cases()),
        ]);
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

    // ── Bantuan ───────────────────────────────────────────────────────────────

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
