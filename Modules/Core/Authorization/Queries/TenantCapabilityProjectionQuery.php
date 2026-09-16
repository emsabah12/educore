<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Queries;

use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\DTO\TenantCapabilityProjection;
use Modules\Core\Authorization\Exceptions\CapabilityProjectionContextException;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;

final readonly class TenantCapabilityProjectionQuery
{
    public function __construct(
        private PermissionCatalogQuery $permissionCatalogQuery,
        private AuthorizationServiceInterface $authorizationService,
        private AuthorizationContextResolverInterface $contextResolver,
        private ActiveUserResolverInterface $activeUserResolver,
    ) {}

    public function execute(): TenantCapabilityProjection
    {
        /*
         * Resolve trusted User → Membership → Tenant context.
         *
         * Caller tidak boleh memasukkan tenantId, membershipId,
         * atau userId sendiri.
         */
        $context = $this->contextResolver->resolve();

        /*
         * Global superadmin status harus dibaca ulang dari canonical
         * active User, bukan dari bearer-token claim maupun client.
         */
        $user = $this->activeUserResolver->findActiveById(
            $context->userId(),
        );

        if ($user === null) {
            throw CapabilityProjectionContextException::unresolvedAuthenticatedUser();
        }

        $permissionCatalog = $this->permissionCatalogQuery
            ->execute();

        $isGlobalSuperadmin = (bool) $user->getAttribute(
            'is_superadmin',
        );

        /*
         * Existing tenant authorization middleware memberikan
         * global-superadmin bypass. Agar read projection konsisten
         * dengan actual backend enforcement, superadmin memperoleh
         * seluruh canonical permission yang memang terdaftar.
         *
         * Tidak dibuat wildcard atau synthetic permission.
         */
        if ($isGlobalSuperadmin) {
            return new TenantCapabilityProjection(
                tenantId: $context->tenantId(),
                membershipId: $context->membershipId(),
                isGlobalSuperadmin: true,
                isTenantAdmin: true,
                permissions: $permissionCatalog,
            );
        }

        $effectivePermissions = [];

        foreach ($permissionCatalog as $permissionName) {
            if (
                $this->authorizationService->hasPermission(
                    $permissionName,
                )
            ) {
                $effectivePermissions[] = $permissionName;
            }
        }

        /*
         * §Kelola Anggota & Role — 'admin' SENGAJA dicek lewat NAMA
         * ROLE literal (hasRole), BUKAN permission granular, supaya
         * konsisten dengan 'tenant.role:admin' yang menggerbang
         * endpoint RBAC-management sesungguhnya (lihat
         * Modules/User/Routes/api.php, Modules/Auth/Routes/api.php).
         * Flag ini murni untuk visibilitas UI (tampilkan/sembunyikan
         * menu) — otorisasi SESUNGGUHNYA tetap ditegakkan
         * middleware, bukan flag ini.
         */
        return new TenantCapabilityProjection(
            tenantId: $context->tenantId(),
            membershipId: $context->membershipId(),
            isGlobalSuperadmin: false,
            isTenantAdmin: $this->authorizationService->hasRole(
                'admin',
            ),
            permissions: $effectivePermissions,
        );
    }
}
