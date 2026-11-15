<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HR\Contracts\ActiveEmployees;
use Modules\HR\Domain\Employee\EmployeeStatus;

/**
 * Implementasi kontrak ActiveEmployees (dipasang di HRServiceProvider).
 */
final class EloquentActiveEmployees implements ActiveEmployees
{
    public function inOrganization(string $tenantId, string $organizationId): array
    {
        if (! Str::isUuid($organizationId)) {
            return [];
        }

        $rows = $this->activeIn($tenantId, $organizationId)
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->distinct()
            ->get(['e.id', 'p.name']);

        $employees = [];

        foreach ($rows as $row) {
            $employees[] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $employees;
    }

    public function isActiveIn(string $tenantId, string $employeeId, string $organizationId): bool
    {
        if (! Str::isUuid($employeeId) || ! Str::isUuid($organizationId)) {
            return false;
        }

        return $this->activeIn($tenantId, $organizationId)->where('e.id', $employeeId)->exists();
    }

    public function describe(string $tenantId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId)
            ->whereIn('e.id', $employeeIds)
            ->get(['e.id', 'p.name']);

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->id] = ['name' => (string) $row->name, 'active_organization_ids' => []];
        }

        $placements = DB::table('hr_placements as pl')
            ->join('hr_employees as e', 'e.id', '=', 'pl.employee_id')
            ->where('pl.tenant_id', $tenantId)
            ->whereIn('pl.employee_id', $employeeIds)
            ->whereNull('pl.end_date')
            ->where('e.status', EmployeeStatus::Active->value)
            ->get(['pl.employee_id', 'pl.organization_id']);

        foreach ($placements as $placement) {
            $id = (string) $placement->employee_id;

            if (isset($result[$id]) && ! in_array((string) $placement->organization_id, $result[$id]['active_organization_ids'], true)) {
                $result[$id]['active_organization_ids'][] = (string) $placement->organization_id;
            }
        }

        return $result;
    }

    public function searchInTenant(string $tenantId, string $query, int $limit): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $query));

        if ($text === '') {
            return [];
        }

        $rows = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', EmployeeStatus::Active->value)
            ->where('p.name', 'ilike', '%'.addcslashes($text, '%_\\').'%')
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->limit(max(1, min($limit, 20)))
            ->get(['e.id', 'e.membership_id', 'p.name']);

        $employees = [];

        foreach ($rows as $row) {
            $employees[] = ['membership_id' => (string) $row->membership_id, 'employee_id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $employees;
    }

    public function activeMembershipIds(string $tenantId, array $membershipIds): array
    {
        $ids = array_values(array_filter($membershipIds, fn (string $id): bool => Str::isUuid($id)));

        if ($ids === []) {
            return [];
        }

        $result = [];

        foreach (DB::table('hr_employees')
            ->where('tenant_id', $tenantId)
            ->where('status', EmployeeStatus::Active->value)
            ->whereIn('membership_id', $ids)
            ->pluck('membership_id') as $id) {
            $result[] = (string) $id;
        }

        return $result;
    }

    private function activeIn(string $tenantId, string $organizationId): Builder
    {
        return DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('hr_placements as pl', 'pl.employee_id', '=', 'e.id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', EmployeeStatus::Active->value)
            ->where('pl.organization_id', $organizationId)
            ->whereNull('pl.end_date');
    }
}
