<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\EmploymentPeriod;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;
use Modules\HR\Domain\Employee\Placement;

/**
 * Aktifkan kembali pegawai yang sudah berhenti (PRD-001 §4.3: "diangkat kembali" = periode baru;
 * pegawai & Person tetap yang sama, [LAMA] INV-HR-001).
 *
 * [ASUMSI, disetujui owner 2026-10-13] Wajib disertai penempatan baru, agar pegawai langsung
 * terlihat oleh kepala lembaganya (PRD-001 §5.3).
 */
final class RehireEmployee
{
    public function __construct(
        private readonly EmployeeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws EmployeeException
     */
    public function handle(
        string $tenantId,
        string $employeeId,
        string $employmentTypeId,
        ?string $classificationId,
        string $startDate,
        string $organizationId,
        string $positionId,
    ): Employee {
        $startDate = $this->guard->pastOrToday($startDate, 'start_date', 'Tanggal mulai');

        return DB::transaction(function () use ($tenantId, $employeeId, $employmentTypeId, $classificationId, $startDate, $organizationId, $positionId): Employee {
            $employee = $this->guard->lockEmployee($tenantId, $employeeId);

            if ($employee->status !== EmployeeStatus::Ended) {
                throw EmployeeException::rule('form', 'Pegawai ini masih aktif.');
            }

            $lastEnd = EmploymentPeriod::query()
                ->withoutGlobalScope('tenant')
                ->where('employee_id', $employee->id)
                ->max('end_date');

            if (is_string($lastEnd) && $startDate <= substr($lastEnd, 0, 10)) {
                throw EmployeeException::rule('start_date', 'Tanggal mulai harus sesudah tanggal berhenti terakhir ('.EmployeeGuard::formatDate(substr($lastEnd, 0, 10)).').');
            }

            $type = $this->guard->activeCatalogItem(CatalogKind::EmploymentType, $tenantId, $employmentTypeId, 'employment_type_id', 'Jenis pegawai');
            $classification = $classificationId === null
                ? null
                : $this->guard->activeCatalogItem(CatalogKind::Classification, $tenantId, $classificationId, 'classification_id', 'Status kepegawaian');
            $organization = $this->guard->activeOrganization($tenantId, $organizationId);
            $position = $this->guard->activeCatalogItem(CatalogKind::Position, $tenantId, $positionId, 'position_id', 'Jabatan');

            EmploymentPeriod::query()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'employment_type_id' => $type->id,
                'classification_id' => $classification?->id,
                'start_date' => $startDate,
            ]);

            Placement::query()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'organization_id' => $organization->id,
                'position_id' => $position->id,
                'is_primary' => true,
                'start_date' => $startDate,
            ]);

            $employee->status = EmployeeStatus::Active;
            $employee->save();

            $this->audit->record(
                HrAuditAction::EMPLOYEE_REHIRED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                [
                    'jenis_pegawai' => $type->code,
                    'status_kepegawaian' => $classification?->code,
                    'start_date' => $startDate,
                    'lembaga' => $organization->code,
                    'jabatan' => $position->code,
                ],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $employee;
        });
    }
}
