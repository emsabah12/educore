<?php

namespace Modules\HR\Application\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EmploymentPeriod;
use Modules\HR\Domain\Employee\EndReason;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;

/**
 * Ganti jenis/status kepegawaian per tanggal tertentu (PRD-001 §4.3), mis. Kontrak → Tetap
 * per 1 Juli: periode lama ditutup 30 Juni (alasan PERUBAHAN_STATUS), periode baru dibuka 1 Juli.
 * Penempatan tidak berubah.
 */
final class ChangeEmploymentStatus
{
    public function __construct(
        private readonly EmployeeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws EmployeeException
     */
    public function handle(string $tenantId, string $employeeId, string $employmentTypeId, ?string $classificationId, string $effectiveDate): EmploymentPeriod
    {
        $effectiveDate = $this->guard->pastOrToday($effectiveDate, 'effective_date', 'Tanggal berlaku');

        return DB::transaction(function () use ($tenantId, $employeeId, $employmentTypeId, $classificationId, $effectiveDate): EmploymentPeriod {
            $employee = $this->guard->lockEmployee($tenantId, $employeeId);
            $this->guard->assertActive($employee);
            $current = $this->guard->openPeriod($employee);

            $type = $this->guard->activeCatalogItem(CatalogKind::EmploymentType, $tenantId, $employmentTypeId, 'employment_type_id', 'Jenis pegawai');
            $classification = $classificationId === null
                ? null
                : $this->guard->activeCatalogItem(CatalogKind::Classification, $tenantId, $classificationId, 'classification_id', 'Status kepegawaian');

            if ($current->employment_type_id === $type->id && $current->classification_id === $classification?->id) {
                throw EmployeeException::rule('employment_type_id', 'Jenis dan status kepegawaian sama dengan yang sekarang.');
            }

            $currentStart = $current->start_date->toDateString();

            if ($effectiveDate <= $currentStart) {
                throw EmployeeException::rule('effective_date', 'Tanggal berlaku harus sesudah awal periode sekarang ('.EmployeeGuard::formatDate($currentStart).').');
            }

            $oldType = $current->employment_type_id;
            $oldClassification = $current->classification_id;

            $current->end_date = CarbonImmutable::parse(EmployeeGuard::dayBefore($effectiveDate));
            $current->end_reason = EndReason::StatusChanged;
            $current->save();

            $period = EmploymentPeriod::query()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'employment_type_id' => $type->id,
                'classification_id' => $classification?->id,
                'start_date' => $effectiveDate,
            ]);

            $this->audit->record(
                HrAuditAction::STATUS_CHANGED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                [
                    'jenis_pegawai' => [$this->code(CatalogKind::EmploymentType, $oldType), $type->code],
                    'status_kepegawaian' => [$oldClassification === null ? null : $this->code(CatalogKind::Classification, $oldClassification), $classification?->code],
                    'berlaku' => $effectiveDate,
                ],
                tenantId: $tenantId,
            );

            return $period;
        });
    }

    private function code(CatalogKind $kind, string $id): ?string
    {
        $code = $kind->query()->withoutGlobalScope('tenant')->whereKey($id)->value('code');

        return is_string($code) ? $code : null;
    }
}
