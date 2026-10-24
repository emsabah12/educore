<?php

namespace Modules\HR\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Document\EmployeeDocuments;
use Modules\HR\Application\Document\ExpiringDocuments;

/**
 * Halaman "Dokumen akan habis" (PRD-001 §15.2, HR-2b): dokumen pegawai aktif yang sudah habis atau
 * habis dalam ≤ 60 hari. Hanya Admin SDM / Admin Yayasan di tingkat Yayasan (`hr.employees.manage`,
 * OD-HR-02); selain itu 403.
 */
final class ExpiringDocumentController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(ExpiringDocuments $documents): Response
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();

        return Inertia::render('hr/documents/expiring', [
            'rows' => $documents->list($this->tenantContext->require()->tenantId),
            'today' => BusinessClock::todayString(),
            'days' => EmployeeDocuments::EXPIRING_DAYS,
        ]);
    }
}
