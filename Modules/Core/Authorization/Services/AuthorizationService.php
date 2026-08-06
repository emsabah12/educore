<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;

final class AuthorizationService implements AuthorizationServiceInterface
{
    public function __construct(
        private readonly AuthorizationContextResolverInterface $contextResolver,
        private readonly MembershipRoleRepositoryInterface $membershipRoleRepository,
        private readonly RolePermissionRepositoryInterface $rolePermissionRepository,
    ) {}

    public function hasRole(
        string $roleName,
    ): bool {
        $roleName = trim($roleName);

        if ($roleName === '') {
            return false;
        }

        $context = $this->contextResolver->resolve();

        return $this->membershipRoleRepository->membershipHasRole(
            membershipId: $context->membershipId(),
            tenantId: $context->tenantId(),
            roleName: $roleName,
        );
    }

    public function hasPermission(
        string $permissionName,
    ): bool {
        $permissionName = trim($permissionName);

        if ($permissionName === '') {
            return false;
        }

        $context = $this->contextResolver->resolve();

        $roles = $this->membershipRoleRepository
            ->rolesForMembership(
                membershipId: $context->membershipId(),
                tenantId: $context->tenantId(),
            );

        foreach ($roles as $role) {
            if (
                $this->rolePermissionRepository
                ->roleHasPermission(
                    (string) $role->id,
                    $permissionName,
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
