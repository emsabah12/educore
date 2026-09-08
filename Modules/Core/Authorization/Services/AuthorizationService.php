<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;
use Modules\Core\Subscription\Services\TenantSubscriptionService;

final class AuthorizationService implements AuthorizationServiceInterface
{
    public function __construct(
        private readonly AuthorizationContextResolverInterface $contextResolver,
        private readonly MembershipRoleRepositoryInterface $membershipRoleRepository,
        private readonly RolePermissionRepositoryInterface $rolePermissionRepository,
        private readonly TenantSubscriptionService $subscriptionService,
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

    /**
     * §PRD Subscription & Custom Role — role KUSTOM (`tenant_id`
     * terisi) TIDAK dianggap memberi permission apa pun kalau
     * tenant-nya sedang tidak punya fitur `custom_roles` efektif
     * (dicabut/downgrade). Ini titik penegakan SESUNGGUHNYA dari
     * "role kustom di-lock" — bukan sekadar status di database yang
     * tidak diperiksa siapa pun.
     *
     * Biaya query tambahan (`effectiveFeatureCodes()`) HANYA dibayar
     * kalau membership ini benar-benar punya SATU role kustom di
     * daftarnya — dihitung sekali per pemanggilan (di-cache di
     * `$tenantHasCustomRolesFeature`), bukan per role. Untuk
     * membership yang cuma punya role sistem (mayoritas saat ini),
     * TIDAK ADA biaya tambahan sama sekali.
     */
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

        $tenantHasCustomRolesFeature = null;

        foreach ($roles as $role) {
            if ($role->tenant_id !== null) {
                $tenantHasCustomRolesFeature ??= in_array(
                    'custom_roles',
                    $this->subscriptionService->effectiveFeatureCodes(
                        (string) $role->tenant_id,
                    ),
                    true,
                );

                if (! $tenantHasCustomRolesFeature) {
                    continue;
                }
            }

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
