<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Payroll\ManagePayroll;
use Modules\HR\Contracts\EmployeeLookup;

/**
 * Slip gaji (PRD-001 §16.3, HR-5c-2, OD-HR-12) — hanya dari bulan yang sudah DIKUNCI (snapshot):
 *
 * - Admin SDM / Admin Yayasan (`hr.payroll.manage` tingkat Yayasan): cetak slip semua pegawai, satu
 *   lembaga, atau satu pegawai — `/pegawai/gaji/rekap/{bulan}/slip?lembaga=&pegawai=`. Tanpa izin → 403.
 * - Pegawai: menu Gaji saya — daftar bulan terkunci & slip miliknya sendiri (`/gaji-saya/{bulan}`).
 *   Bulan belum dikunci atau tanpa slip → 404. Tanpa permission (seperti Cuti saya).
 */
final class PayslipController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
    ) {}

    public function print(Request $request, string $month, ManagePayroll $payroll): Response
    {
        $this->authorization->inspectTarget(HrAccess::PAYROLL_MANAGE, null, null)->authorize();
        $tenantId = $this->tenantContext->require()->tenantId;

        $organization = $request->string('lembaga')->value();
        $employee = $request->string('pegawai')->value();
        $slips = $payroll->lockedSlips(
            $tenantId,
            $month,
            Str::isUuid($organization) ? $organization : null,
            Str::isUuid($employee) ? $employee : null,
        );

        if ($slips === null) {
            abort(404);
        }

        return $this->render($tenantId, $month, $slips, $payroll, "/pegawai/gaji/rekap?bulan={$month}", 'Kembali ke rekap gaji');
    }

    public function myIndex(ManagePayroll $payroll): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);

        return Inertia::render('hr/my-pay', [
            'linked' => $employeeId !== null,
            'months' => $employeeId === null ? [] : $payroll->monthsFor($context->tenantId, $employeeId),
        ]);
    }

    public function mySlip(string $month, ManagePayroll $payroll): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId) ?? abort(404);

        $slips = $payroll->lockedSlips($context->tenantId, $month, null, $employeeId);

        if ($slips === null || $slips === []) {
            abort(404);
        }

        return $this->render($context->tenantId, $month, $slips, $payroll, '/gaji-saya', 'Kembali ke Gaji saya');
    }

    /**
     * @param  list<array<string, mixed>>  $slips
     */
    private function render(string $tenantId, string $month, array $slips, ManagePayroll $payroll, string $backUrl, string $backLabel): Response
    {
        return Inertia::render('print/payslips', [
            'tenant' => (string) Tenant::query()->whereKey($tenantId)->value('name'),
            'month' => $month,
            'label' => AttendanceRecap::monthLabel($month),
            'lock' => $payroll->lockInfo($tenantId, $month),
            'slips' => $slips,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }
}
