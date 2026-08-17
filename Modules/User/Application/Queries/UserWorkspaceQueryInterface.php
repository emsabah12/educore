<?php

declare(strict_types=1);

namespace Modules\User\Application\Queries;

use Illuminate\Support\Collection;
use Modules\User\Application\DTO\WorkspaceSummary;

interface UserWorkspaceQueryInterface
{
    /**
     * Return active organizational workspace projections for the exact
     * verified Membership + Tenant context.
     *
     * @return Collection<int, WorkspaceSummary>
     */
    public function findActiveForMembershipAndTenant(
        string $membershipId,
        string $tenantId,
    ): Collection;
}
