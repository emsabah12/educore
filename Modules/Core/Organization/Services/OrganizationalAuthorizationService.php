<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Services;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAuthorizationServiceInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Contracts\OrganizationalScopedRoleRepositoryInterface;
use Modules\Core\Organization\Exceptions\OrganizationalContextException;

final readonly class OrganizationalAuthorizationService implements
    OrganizationalAuthorizationServiceInterface
{
    public function __construct(
        private OrganizationalContextInterface $organizationalContext,
        private OrganizationalContextResolverInterface $contextResolver,
        private MembershipRoleRepositoryInterface $membershipRoleRepository,
        private OrganizationalScopedRoleRepositoryInterface $scopedRoleRepository,
        private RolePermissionRepositoryInterface $rolePermissionRepository,
    ) {
    }

    public function hasRole(
        string $roleName,
    ): bool {
        $roleName = trim($roleName);

        if ($roleName === '') {
            return false;
        }

        $roles = $this->resolveEffectiveRoles();

        if ($roles === null) {
            return false;
        }

        return $roles->contains(
            static fn (Role $role): bool =>
                (string) $role->name === $roleName,
        );
    }

    public function hasPermission(
        string $permissionName,
    ): bool {
        $permissionName = trim($permissionName);

        if ($permissionName === '') {
            return false;
        }

        $roles = $this->resolveEffectiveRoles();

        if ($roles === null) {
            return false;
        }

        foreach ($roles as $role) {
            if (
                $this->rolePermissionRepository
                    ->roleHasPermission(
                        (string) $role->getKey(),
                        $permissionName,
                    )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Role>|null
     */
    private function resolveEffectiveRoles(): ?Collection
    {
        $currentContext =
            $this->organizationalContext->getCurrentContext();

        if ($currentContext === null) {
            return null;
        }

        try {
            /*
             * The in-memory context is a locator, not stale authority.
             * Re-resolve it before every authorization evaluation.
             */
            $context = $this->contextResolver->resolve(
                $currentContext->assignmentId,
            );
        } catch (OrganizationalContextException) {
            return null;
        }

        $tenantRoles = $this->membershipRoleRepository
            ->rolesForMembership(
                membershipId: $context->membershipId,
                tenantId: $context->tenantId,
            );

        $scopedRoles = $this->scopedRoleRepository
            ->rolesForContext(
                tenantId: $context->tenantId,
                membershipId: $context->membershipId,
                organizationId: $context->organizationId,
                organizationUnitId: $context->organizationUnitId,
            );

        return $tenantRoles
            ->merge($scopedRoles)
            ->unique(
                static fn (Role $role): string =>
                    (string) $role->getKey(),
            )
            ->values();
    }
}
