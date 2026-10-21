<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Support\Str;
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

    public function employeeIdForMembership(string $tenantId, string $membershipId): ?string
    {
        if (! Str::isUuid($membershipId)) {
            return null;
        }

        $id = Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)
            ->value('id');

        return $id === null ? null : (string) $id;
    }
}
