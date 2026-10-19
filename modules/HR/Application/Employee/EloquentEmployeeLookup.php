<?php

namespace Modules\HR\Application\Employee;

use Modules\HR\Contracts\EmployeeLookup;
use Modules\HR\Domain\Employee\Employee;

/**
 * Implementasi kontrak EmployeeLookup (dipasang di HRServiceProvider).
 */
final class EloquentEmployeeLookup implements EmployeeLookup
{
    public function employeeMembershipIds(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $ids = [];

        $rows = Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('membership_id', $membershipIds)
            ->pluck('membership_id');

        foreach ($rows as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }
}
