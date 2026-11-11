<?php

namespace Modules\HR\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Discipline\DisciplineRecords;
use Modules\HR\Application\Discipline\ManageDiscipline;
use Modules\HR\Application\Document\EmployeeDocuments;
use Modules\HR\Application\Document\ManageEmployeeDocuments;
use Modules\HR\Contracts\EmployeeLookup;
use Modules\HR\Domain\Discipline\Exceptions\DisciplineException;
use Modules\HR\Domain\Document\EmployeeDocument;
use Modules\HR\Domain\Document\Exceptions\DocumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Dokumen saya — layanan mandiri pegawai (PRD-001 §15.2, HR-2b). Tanpa permission: akun tertaut ke
 * data pegawai di yayasan aktif (seperti Cuti saya). Hanya dokumen yang berlaku [ASUMSI]; dokumen
 * yang dibatalkan dan dokumen pegawai lain → 404.
 *
 * Bagian "Catatan disiplin" (PRD-001 §18.1, HR-7a; OD-HR-13): pegawai melihat semua catatannya sendiri beserta
 * statusnya (termasuk yang dicabut) dan mengunduh lampirannya; catatan pegawai lain → 404.
 */
final class MyDocumentController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
    ) {}

    public function index(EmployeeDocuments $documents, DisciplineRecords $discipline): Response
    {
        $employeeId = $this->employeeId();

        if ($employeeId === null) {
            return Inertia::render('hr/my-documents', ['linked' => false, 'documents' => [], 'discipline' => []]);
        }

        $rows = array_values(array_filter(
            $documents->forEmployee($this->tenantContext->require()->tenantId, $employeeId),
            fn (array $row): bool => ! $row['voided'],
        ));

        return Inertia::render('hr/my-documents', [
            'linked' => true,
            'documents' => $rows,
            'discipline' => $discipline->forEmployee($this->tenantContext->require()->tenantId, $employeeId),
        ]);
    }

    public function download(string $document, ManageEmployeeDocuments $documents): BinaryFileResponse
    {
        $employeeId = $this->employeeId() ?? abort(404);
        $tenantId = $this->tenantContext->require()->tenantId;

        $active = EmployeeDocument::query()
            ->where('employee_id', $employeeId)
            ->whereKey($document)
            ->whereNull('voided_at')
            ->exists();

        if (! $active) {
            abort(404);
        }

        try {
            $file = $documents->download($tenantId, $employeeId, $document);
        } catch (DocumentException) {
            abort(404);
        }

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadDiscipline(string $record, ManageDiscipline $discipline): BinaryFileResponse
    {
        $employeeId = $this->employeeId() ?? abort(404);

        try {
            $file = $discipline->attachment($this->tenantContext->require()->tenantId, $employeeId, $record);
        } catch (DisciplineException) {
            abort(404);
        }

        return DisciplineController::send($file);
    }

    private function employeeId(): ?string
    {
        $context = $this->tenantContext->require();

        return $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);
    }
}
