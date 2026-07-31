<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\MembershipRole;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;

final class EloquentMembershipRoleRepository implements MembershipRoleRepositoryInterface
{
    /**
     * @return Collection<int, Role>
     */
    public function rolesForMembership(
        string $membershipId,
    ): Collection {
        return Role::query()
            ->select('roles.*')
            ->join(
                'membership_roles',
                'roles.id',
                '=',
                'membership_roles.role_id'
            )
            ->where(
                'membership_roles.membership_id',
                $membershipId
            )
            ->orderBy('roles.name')
            ->get();
    }

    public function membershipHasRole(
        string $membershipId,
        string $roleName,
    ): bool {
        return Role::query()
            ->join(
                'membership_roles',
                'roles.id',
                '=',
                'membership_roles.role_id'
            )
            ->where(
                'membership_roles.membership_id',
                $membershipId
            )
            ->where(
                'roles.name',
                $roleName
            )
            ->exists();
    }

    /**
     * @return Collection<int, MembershipRole>
     */
    public function findByMembership(
        string $membershipId,
    ): Collection {
        return MembershipRole::query()
            ->where(
                'membership_id',
                $membershipId
            )
            ->orderBy('role_id')
            ->get();
    }

    public function save(
        MembershipRole $membershipRole,
    ): MembershipRole {
        $membershipRole->save();

        return $membershipRole;
    }

    public function delete(
        MembershipRole $membershipRole,
    ): void {
        $membershipRole->delete();
    }
}
