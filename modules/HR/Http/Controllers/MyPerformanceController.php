<?php

namespace Modules\HR\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Performance\PerformanceRecap;
use Modules\HR\Contracts\EmployeeLookup;

/**
 * Kinerja saya — hasil penilaian kinerja pegawai sendiri (PRD-001 §19.2, HR-8b-2; OD-HR-17). Tanpa permission: akun
 * tertaut ke data pegawai di yayasan aktif. Hanya periode yang sudah dikunci yang tampil.
 */
final class MyPerformanceController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
    ) {}

    public function index(PerformanceRecap $recap): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);

        return Inertia::render('hr/my-performance', [
            'linked' => $employeeId !== null,
            'results' => $employeeId === null ? [] : $recap->forEmployee($context->tenantId, $employeeId),
        ]);
    }
}
