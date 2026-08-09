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

    public function findActiveMembershipByIdForUser(
        string $membershipId,
        string $userId,
    ): ?Membership {
        $membershipId = trim($membershipId);
        $userId = trim($userId);

        if (
            $membershipId === ''
            || $userId === ''
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
                'memberships.user_id',
                $userId,
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
     * Membership merupakan explicit tenant-bound aggregate.
     *
     * Repository tidak menggunakan ambient TenantContext karena
     * beberapa operasi membership terjadi sebelum current tenant
     * context tersedia, terutama switch membership.
     *
     * Setiap public query wajib membawa boundary eksplisit
     * melalui membership ID + tenant atau membership ID + owner.
     *
     * @return Builder<Membership>
     */
    private function explicitBoundaryQuery(): Builder
    {
        return Membership::query();
    }
}
