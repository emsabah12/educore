<?php

declare(strict_types=1);

namespace Modules\User\Application\Actions;

use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\User\Application\DTO\WorkspaceDiscoveryResult;
use Modules\User\Application\DTO\WorkspaceSummary;
use Modules\User\Application\Queries\UserWorkspaceQueryInterface;
use RuntimeException;

final readonly class ListMyWorkspaces
{
    public function __construct(
        private MembershipContextResolverInterface $membershipContextResolver,
        private TenantContextInterface $tenantContext,
        private UserWorkspaceQueryInterface $workspaceQuery,
    ) {}

    public function execute(): WorkspaceDiscoveryResult
    {
        /*
         * MembershipContextResolver menggunakan canonical authenticated
         * User + current Tenant + authenticated membership identifier.
         */
        $membershipContext =
            $this->membershipContextResolver->resolve();

        $tenant = $this->tenantContext
            ->getCurrentTenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException(
                'Verified Tenant context is required.',
            );
        }

        $tenantId = trim(
            (string) $tenant->getKey(),
        );

        if (
            $tenantId === ''
            || $tenantId !== $membershipContext->tenantId
        ) {
            throw new RuntimeException(
                'Membership and Tenant contexts do not match.',
            );
        }

        $tenantName = trim(
            (string) $tenant->name,
        );

        if ($tenantName === '') {
            throw new RuntimeException(
                'Tenant name is required for workspace projection.',
            );
        }

        /*
         * Tenant workspace selalu tersedia setelah canonical
         * Membership/Tenant context berhasil diverifikasi.
         */
        $workspaces = collect([
            new WorkspaceSummary(
                type: WorkspaceSummary::TYPE_TENANT,
                organizationalAssignmentId: null,
                organizationId: null,
                organizationUnitId: null,
                label: $tenantName,
            ),
        ])->concat(
            $this->workspaceQuery
                ->findActiveForMembershipAndTenant(
                    membershipId: $membershipContext->membershipId,
                    tenantId: $membershipContext->tenantId,
                ),
        )->values();

        return new WorkspaceDiscoveryResult(
            tenantId: $tenantId,
            tenantName: $tenantName,
            workspaces: $workspaces,
        );
    }
}
