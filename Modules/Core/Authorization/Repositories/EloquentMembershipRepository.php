<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;

final class EloquentMembershipRepository implements MembershipRepositoryInterface
{
    public function findActiveMembershipByIdAndTenant(
        string $membershipId,
        string $tenantId,
    ): ?Membership {
        $membershipId = trim($membershipId);
        $tenantId = trim($tenantId);

        if (
            $membershipId === ''
            || $tenantId === ''
        ) {
            return null;
        }

        return $this->explicitBoundaryQuery()
            ->whereKey($membershipId)
            ->where(
                'memberships.tenant_id',
                $tenantId,
            )
            ->where(
                'memberships.status',
                'ACTIVE',
            )
            ->first();
    }

    public function findActiveMembershipByIdForPerson(
        string $membershipId,
        string $personId,
    ): ?Membership {
        $membershipId = trim($membershipId);
        $personId = trim($personId);

        if (
            $membershipId === ''
            || $personId === ''
        ) {
            return null;
        }

        return $this->explicitBoundaryQuery()
            ->select('memberships.*')
            ->join(
                'tenants',
                'memberships.tenant_id',
                '=',
                'tenants.id',
            )
            ->where(
                'memberships.id',
                $membershipId,
            )
            ->where(
                'memberships.person_id',
                $personId,
            )
            ->where(
                'memberships.status',
                'ACTIVE',
            )
            ->where(
                'tenants.is_active',
                true,
            )
            ->first();
    }

    /**
     * Membership is an explicit tenant-bound aggregate.
     *
     * This repository intentionally does not depend on ambient TenantContext,
     * because membership selection can happen before a target tenant becomes
     * the current runtime context.
     *
     * @return Builder<Membership>
     */
    private function explicitBoundaryQuery(): Builder
    {
        return Membership::query();
    }
}
