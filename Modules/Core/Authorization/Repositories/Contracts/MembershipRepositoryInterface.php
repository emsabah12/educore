<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Modules\Core\Authorization\Models\Membership;

interface MembershipRepositoryInterface
{
    /**
     * Find an active membership by explicit membership and tenant boundary.
     */
    public function findActiveMembershipByIdAndTenant(
        string $membershipId,
        string $tenantId,
    ): ?Membership;

    /**
     * Find an active membership by explicit membership and canonical Person.
     *
     * Used when selecting/switching memberships before the target tenant has
     * become the current runtime TenantContext.
     */
    public function findActiveMembershipByIdForPerson(
        string $membershipId,
        string $personId,
    ): ?Membership;
}
