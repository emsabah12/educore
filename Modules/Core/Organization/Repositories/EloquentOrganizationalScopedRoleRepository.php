<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Repositories;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Organization\Contracts\OrganizationalScopedRoleRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentOrganizationalScopedRoleRepository implements
    OrganizationalScopedRoleRepositoryInterface
{
    /**
     * @return Collection<int, Role>
     */
    public function rolesForContext(
        string $tenantId,
        string $membershipId,
        string $organizationId,
        ?string $organizationUnitId,
    ): Collection {
        $tenantId = trim($tenantId);
        $membershipId = trim($membershipId);
        $organizationId = trim($organizationId);
        $organizationUnitId = is_string($organizationUnitId)
            ? trim($organizationUnitId)
            : null;

        if (
            ! UuidV7::validate($tenantId)
            || ! UuidV7::validate($membershipId)
            || ! UuidV7::validate($organizationId)
            || (
                $organizationUnitId !== null
                && ! UuidV7::validate($organizationUnitId)
            )
        ) {
            return collect();
        }

        $query = Role::query()
            ->select('roles.*')
            ->join(
                'organizational_assignment_roles',
                'roles.id',
                '=',
                'organizational_assignment_roles.role_id',
            )
            ->join(
                'organizational_assignments',
                'organizational_assignment_roles.organizational_assignment_id',
                '=',
                'organizational_assignments.id',
            )
            ->join(
                'memberships',
                'organizational_assignments.membership_id',
                '=',
                'memberships.id',
            )
            ->join(
                'organizations',
                'organizational_assignments.organization_id',
                '=',
                'organizations.id',
            )
            ->leftJoin(
                'organization_units',
                'organizational_assignments.organization_unit_id',
                '=',
                'organization_units.id',
            )
            ->where(
                'organizational_assignments.tenant_id',
                $tenantId,
            )
            ->where(
                'organizational_assignments.membership_id',
                $membershipId,
            )
            ->where(
                'organizational_assignments.organization_id',
                $organizationId,
            )
            ->where(
                'organizational_assignments.status',
                'ACTIVE',
            )
            ->where(
                'memberships.tenant_id',
                $tenantId,
            )
            ->where(
                'memberships.status',
                'ACTIVE',
            )
            ->where(
                'organizations.tenant_id',
                $tenantId,
            )
            ->where(
                'organizations.is_active',
                true,
            )
            ->where(function ($scopeQuery): void {
                $scopeQuery
                    ->whereNull(
                        'organizational_assignments.organization_unit_id',
                    )
                    ->orWhere(function ($unitScopeQuery): void {
                        $unitScopeQuery
                            ->whereNotNull(
                                'organizational_assignments.organization_unit_id',
                            )
                            ->where(
                                'organization_units.is_active',
                                true,
                            );
                    });
            });

        if ($organizationUnitId === null) {
            $query->whereNull(
                'organizational_assignments.organization_unit_id',
            );
        } else {
            $query->where(function ($scopeQuery) use (
                $organizationUnitId,
            ): void {
                $scopeQuery
                    ->whereNull(
                        'organizational_assignments.organization_unit_id',
                    )
                    ->orWhere(
                        'organizational_assignments.organization_unit_id',
                        $organizationUnitId,
                    );
            });
        }

        return $query
            ->distinct()
            ->orderBy('roles.name')
            ->get();
    }
}
