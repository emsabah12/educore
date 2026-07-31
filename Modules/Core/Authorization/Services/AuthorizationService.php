<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;

final class AuthorizationService implements AuthorizationServiceInterface
{
    public function __construct(
        private readonly AuthorizationContextResolverInterface $contextResolver,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly MembershipRoleRepositoryInterface $membershipRoleRepository,
        private readonly RolePermissionRepositoryInterface $rolePermissionRepository,
    ) {}

    /**
     * Menentukan apakah authenticated user memiliki role
     * pada authorization context saat ini.
     *
     * Security invariants:
     *
     * 1. Identity berasal dari authenticated user.
     * 2. Tenant berasal dari current tenant context.
     * 3. Membership berasal dari AuthorizationContextResolver.
     * 4. Membership harus ACTIVE.
     * 5. Role harus terhubung melalui membership_roles.
     * 6. memberships.role tidak pernah digunakan sebagai
     *    authorization source.
     */
    public function hasRole(
        string $roleName,
    ): bool {
        $context = $this->contextResolver->resolve();

        return $this->membershipRoleRepository->membershipHasRole(
            $context->membershipId(),
            $roleName,
        );
    }

    /**
     * Menentukan apakah authenticated user memiliki permission
     * pada authorization context saat ini.
     *
     * Security invariants:
     *
     * 1. Identity berasal dari authenticated user.
     * 2. Tenant berasal dari current tenant context.
     * 3. Membership berasal dari AuthorizationContextResolver.
     * 4. Membership harus ACTIVE.
     * 5. Permission harus berasal dari role membership.
     * 6. Permission resolution tidak menggunakan memberships.role.
     */
    public function hasPermission(
        string $permissionName,
    ): bool {
        $context = $this->contextResolver->resolve();

        $roles = $this->membershipRoleRepository->rolesForMembership(
            $context->membershipId(),
        );

        foreach ($roles as $role) {
            if (
                $this->rolePermissionRepository->roleHasPermission(
                    $role->id,
                    $permissionName,
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
