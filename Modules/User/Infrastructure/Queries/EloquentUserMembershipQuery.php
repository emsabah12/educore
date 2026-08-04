<?php

declare(strict_types=1);

namespace Modules\User\Infrastructure\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\User\Application\DTO\MembershipSummary;
use Modules\User\Application\Queries\UserMembershipQueryInterface;

final class EloquentUserMembershipQuery implements UserMembershipQueryInterface
{
    /**
     * @return Collection<int, MembershipSummary>
     */
    public function findActiveForUser(
        string $userId,
    ): Collection {
        $userId = trim($userId);

        if ($userId === '') {
            return collect();
        }

        return DB::table('memberships')
            ->join(
                'tenants',
                'memberships.tenant_id',
                '=',
                'tenants.id',
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
            ->select([
                'memberships.id as membership_id',
                'memberships.status as membership_status',
                'tenants.id as tenant_id',
                'tenants.name as tenant_name',
                'tenants.subdomain as tenant_subdomain',
            ])
            ->orderBy('tenants.name')
            ->get()
            ->map(
                static fn(object $row): MembershipSummary =>
                new MembershipSummary(
                    membershipId: (string) $row->membership_id,
                    membershipStatus: (string) $row->membership_status,
                    tenantId: (string) $row->tenant_id,
                    tenantName: (string) $row->tenant_name,
                    tenantSubdomain: (string) $row->tenant_subdomain,
                ),
            )
            ->values();
    }
}
