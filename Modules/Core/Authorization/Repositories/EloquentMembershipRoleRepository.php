<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;

final class EloquentMembershipRoleRepository implements MembershipRoleRepositoryInterface
{
    /**
     * @return Collection<int, Role>
     */
    public function rolesForMembership(
        string $membershipId,
        string $tenantId,
    ): Collection {
        $membershipId = trim($membershipId);
        $tenantId = trim($tenantId);

        if (
            ! UuidV7::validate($membershipId)
            || ! UuidV7::validate($tenantId)
        ) {
            return collect();
        }

        return Role::query()
            ->select('roles.*')
            ->join(
                'membership_roles',
                'roles.id',
                '=',
                'membership_roles.role_id',
            )
            ->join(
                'memberships',
                'membership_roles.membership_id',
                '=',
                'memberships.id',
            )
            ->where(
                'membership_roles.membership_id',
                $membershipId,
            )
            ->where(
                'memberships.tenant_id',
                $tenantId,
            )
            ->where(
                'memberships.status',
                'ACTIVE',
            )
            ->orderBy('roles.name')
            ->get();
    }

    public function membershipHasRole(
        string $membershipId,
        string $tenantId,
        string $roleName,
    ): bool {
        $membershipId = trim($membershipId);
        $tenantId = trim($tenantId);
        $roleName = trim($roleName);

        if (
            ! UuidV7::validate($membershipId)
            || ! UuidV7::validate($tenantId)
            || $roleName === ''
        ) {
            return false;
        }

        return Role::query()
            ->join(
                'membership_roles',
                'roles.id',
                '=',
                'membership_roles.role_id',
            )
            ->join(
                'memberships',
                'membership_roles.membership_id',
                '=',
                'memberships.id',
            )
            ->where(
                'membership_roles.membership_id',
                $membershipId,
            )
            ->where(
                'memberships.tenant_id',
                $tenantId,
            )
            ->where(
                'memberships.status',
                'ACTIVE',
            )
            ->where(
                'roles.name',
                $roleName,
            )
            ->exists();
    }

    public function assignRole(
        string $membershipId,
        string $tenantId,
        string $roleId,
    ): void {
        $membershipId = trim($membershipId);
        $tenantId = trim($tenantId);
        $roleId = trim($roleId);

        if (! UuidV7::validate($membershipId)) {
            throw new RuntimeException(
                'Membership identifier must be a valid UUIDv7.',
            );
        }

        if (! UuidV7::validate($tenantId)) {
            throw new RuntimeException(
                'Tenant identifier must be a valid UUIDv7.',
            );
        }

        if (! UuidV7::validate($roleId)) {
            throw new RuntimeException(
                'Role identifier must be a valid UUIDv7.',
            );
        }

        $membershipExists = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $membershipExists) {
            throw new RuntimeException(
                'Active membership was not found in the requested tenant.',
            );
        }

        $roleExists = DB::table('roles')
            ->where('id', $roleId)
            ->exists();

        if (! $roleExists) {
            throw new RuntimeException(
                'Role was not found.',
            );
        }

        /*
         * Composite primary key membership_id + role_id membuat operasi
         * ini idempotent. insertOrIgnore menghindari UPDATE tanpa perubahan
         * seperti yang terjadi pada updateOrInsert sebelumnya.
         */
        DB::table('membership_roles')->insertOrIgnore([
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);
    }
}
