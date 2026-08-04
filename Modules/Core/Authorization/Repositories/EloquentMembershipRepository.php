<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;

final class EloquentMembershipRepository implements MembershipRepositoryInterface
{
    public function findById(
        string $id,
    ): ?Membership {
        return Membership::query()
            ->find($id);
    }

    public function findActiveMembership(
        string $userId,
        string $tenantId,
    ): ?Membership {
        return Membership::query()
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->orderByDesc('created_at')
            ->first();
    }

    public function findActiveMembershipByIdAndTenant(
        string $membershipId,
        string $tenantId,
    ): ?Membership {
        return Membership::query()
            ->whereKey($membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->first();
    }

    public function findActiveMembershipByIdForUser(
        string $membershipId,
        string $userId,
    ): ?Membership {
        return Membership::query()
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
     * @return Collection<int, Membership>
     */
    public function findByUser(
        string $userId,
    ): Collection {
        return Membership::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, Membership>
     */
    public function findByTenant(
        string $tenantId,
    ): Collection {
        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, Membership>
     */
    public function all(): Collection
    {
        return Membership::query()
            ->orderBy('created_at')
            ->get();
    }

    public function exists(
        string $id,
    ): bool {
        return Membership::query()
            ->whereKey($id)
            ->exists();
    }

    public function save(
        Membership $membership,
    ): Membership {
        $membership->save();

        return $membership;
    }

    public function delete(
        Membership $membership,
    ): void {
        $membership->delete();
    }
}
