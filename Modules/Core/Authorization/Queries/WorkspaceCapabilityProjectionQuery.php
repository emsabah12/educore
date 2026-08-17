<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Queries;

use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\DTO\WorkspaceCapabilityProjection;
use Modules\Core\Authorization\Exceptions\CapabilityProjectionContextException;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Organization\Contracts\OrganizationalAuthorizationServiceInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Exceptions\OrganizationalContextException;

final readonly class WorkspaceCapabilityProjectionQuery
{
    public function __construct(
        private PermissionCatalogQuery $permissionCatalogQuery,
        private AuthorizationContextResolverInterface $authorizationContextResolver,
        private OrganizationalContextInterface $organizationalContext,
        private OrganizationalContextResolverInterface $organizationalContextResolver,
        private OrganizationalAuthorizationServiceInterface $organizationalAuthorizationService,
        private ActiveUserResolverInterface $activeUserResolver,
    ) {}

    public function execute(): WorkspaceCapabilityProjection
    {
        /*
         * Resolve canonical authenticated User → Membership → Tenant
         * context. Caller tidak boleh menentukan identifiers sendiri.
         */
        $authorizationContext =
            $this->authorizationContextResolver->resolve();

        /*
         * Workspace capability hanya valid ketika request sudah
         * memiliki verified organizational context.
         */
        $currentOrganizationalContext =
            $this->organizationalContext
            ->getCurrentContext();

        if ($currentOrganizationalContext === null) {
            throw CapabilityProjectionContextException
                ::missingOrganizationalContext();
        }

        /*
         * Context yang tersimpan di memory adalah locator, bukan
         * persistent authority. Re-resolve assignment sebelum
         * memproyeksikan scope ke frontend.
         */
        try {
            $resolvedOrganizationalContext =
                $this->organizationalContextResolver
                ->resolve(
                    $currentOrganizationalContext
                        ->assignmentId,
                );
        } catch (OrganizationalContextException) {
            throw CapabilityProjectionContextException
                ::unresolvedOrganizationalContext();
        }

        /*
         * Defense-in-depth:
         *
         * Organizational context harus masih berada pada canonical
         * Tenant + Membership yang sama dengan authenticated context.
         */
        if (
            $resolvedOrganizationalContext->tenantId
            !== $authorizationContext->tenantId()
            || $resolvedOrganizationalContext->membershipId
            !== $authorizationContext->membershipId()
        ) {
            throw CapabilityProjectionContextException
                ::organizationalContextMismatch();
        }

        /*
         * is_global_superadmin adalah canonical User metadata.
         * Nilai ini tidak dibaca dari bearer token atau request.
         */
        $user = $this->activeUserResolver
            ->findActiveById(
                $authorizationContext->userId(),
            );

        if ($user === null) {
            throw CapabilityProjectionContextException
                ::unresolvedAuthenticatedUser();
        }

        $permissionCatalog =
            $this->permissionCatalogQuery->execute();

        $effectivePermissions = [];

        /*
         * Penting:
         *
         * Tidak ada superadmin short-circuit pada workspace
         * projection. OrganizationalAuthorizationService adalah
         * authority evaluator canonical untuk scoped context.
         */
        foreach ($permissionCatalog as $permissionName) {
            if (
                $this->organizationalAuthorizationService
                ->hasPermission(
                    $permissionName,
                )
            ) {
                $effectivePermissions[] =
                    $permissionName;
            }
        }

        return new WorkspaceCapabilityProjection(
            tenantId: $resolvedOrganizationalContext->tenantId,
            membershipId: $resolvedOrganizationalContext->membershipId,
            organizationalAssignmentId: $resolvedOrganizationalContext->assignmentId,
            organizationId: $resolvedOrganizationalContext->organizationId,
            organizationUnitId: $resolvedOrganizationalContext
                ->organizationUnitId,
            isGlobalSuperadmin: (bool) $user->getAttribute(
                'is_superadmin',
            ),
            permissions: $effectivePermissions,
        );
    }
}
