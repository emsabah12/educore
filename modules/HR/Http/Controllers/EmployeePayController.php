<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Payroll\EmployeePay;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;

/**
 * Simpan nominal komponen gaji seorang pegawai (PRD-001 §16.1, HR-5a). Hanya `hr.payroll.manage`
 * tingkat Yayasan → selain itu 403; pegawai yayasan lain → 404.
 */
final class EmployeePayController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function update(Request $request, string $employee, EmployeePay $pay): RedirectResponse
    {
        $this->authorization->inspectTarget(HrAccess::PAYROLL_MANAGE, null, null)->authorize();

        $target = Str::isUuid($employee) ? Employee::query()->find($employee) : null;

        if (! $target instanceof Employee) {
            abort(404);
        }

        $request->validate([
            'amounts' => ['present', 'array', 'max:200'],
            'amounts.*' => ['nullable', 'string', 'max:30'],
        ]);

        $amounts = $request->input('amounts', []);
        $context = $this->tenantContext->require();

        try {
            $changed = $pay->save($context->tenantId, $target->id, is_array($amounts) ? $amounts : [], $context->membershipId);
        } catch (PayrollException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === [] ? 'Tidak ada perubahan.' : 'Komponen gaji disimpan ('.count($changed).' diubah).']);

        return back();
    }
}
