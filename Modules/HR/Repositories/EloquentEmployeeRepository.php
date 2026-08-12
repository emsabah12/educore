<?php

declare(strict_types=1);

namespace Modules\HR\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Models\Employee;

final class EloquentEmployeeRepository implements EmployeeRepositoryInterface
{
    public function getByTenantPaginated(
        string $tenantId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->baseTenantQuery($tenantId)
            ->orderBy('persons.name')
            ->orderBy('employees.id')
            ->paginate($perPage);
    }

    public function findByIdForTenant(
        string $id,
        string $tenantId,
    ): array {
        $employee = $this->baseTenantQuery($tenantId)
            ->where('employees.id', $id)
            ->first();

        if ($employee === null) {
            throw (new ModelNotFoundException())->setModel(
                Employee::class,
                [$id],
            );
        }

        return (array) $employee;
    }

    public function findByMembershipForTenant(
        string $membershipId,
        string $tenantId,
    ): ?array {
        $employee = $this->baseTenantQuery($tenantId)
            ->where('employees.membership_id', $membershipId)
            ->first();

        return $employee === null
            ? null
            : (array) $employee;
    }

    public function createProfileForTenant(
        string $tenantId,
        string $membershipId,
        array $data,
    ): array {
        $membershipExists = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $membershipExists) {
            throw (new ModelNotFoundException())->setModel(
                Membership::class,
                [$membershipId],
            );
        }

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => $data['nip'],
            'jabatan' => $data['jabatan'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findByIdForTenant(
            $employeeId,
            $tenantId,
        );
    }

    private function baseTenantQuery(string $tenantId): Builder
    {
        return DB::table('employees')
            ->join(
                'memberships',
                'employees.membership_id',
                '=',
                'memberships.id',
            )
            ->join(
                'persons',
                'memberships.person_id',
                '=',
                'persons.id',
            )
            ->select([
                'employees.id as employee_id',
                'employees.membership_id',
                'memberships.person_id as person_id',
                'employees.tenant_id',
                'employees.nip',
                'employees.jabatan',
                'persons.name as nama',
                'memberships.status as membership_status',
                'employees.created_at',
            ])
            ->where('employees.tenant_id', $tenantId)
            ->where('memberships.tenant_id', $tenantId)
            ->whereNull('employees.deleted_at');
    }
}
