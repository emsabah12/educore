<?php

declare(strict_types=1);

namespace Modules\HR\Services\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Employment;

/**
 * Helper locking yang dipakai bersama oleh service-service lifecycle HR
 * (EmploymentLifecycleService, EmploymentPlacementService, dst).
 *
 * Diekstrak di Step 7 karena EmploymentPlacementService membutuhkan
 * persis logika locking yang sama dengan EmploymentLifecycleService —
 * menyalinnya akan melanggar DRY dan berisiko kedua salinan perlahan
 * "menyimpang" kalau salah satu diubah tapi yang lain lupa diikutkan.
 */
trait LocksEmploymentRecords
{
    private function lockEmployeeForTenant(
        string $employeeId,
        string $tenantId,
    ): Employee {
        /** @var Employee|null $employee */
        $employee = Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $employeeId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if ($employee === null) {
            throw (new ModelNotFoundException())->setModel(
                Employee::class,
                [$employeeId],
            );
        }

        return $employee;
    }

    private function lockEmploymentForTenant(
        string $employmentId,
        string $tenantId,
    ): Employment {
        /** @var Employment|null $employment */
        $employment = Employment::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $employmentId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($employment === null) {
            throw (new ModelNotFoundException())->setModel(
                Employment::class,
                [$employmentId],
            );
        }

        return $employment;
    }
}
