<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\MembershipRole;
use Modules\Core\Authorization\Models\Role;

interface MembershipRoleRepositoryInterface
{
    /**
     * @return Collection<int, Role>
     */
    public function rolesForMembership(
        string $membershipId,
    ): Collection;

    public function membershipHasRole(
        string $membershipId,
        string $roleName,
    ): bool;

    /**
     * @return Collection<int, MembershipRole>
     */
    public function findByMembership(
        string $membershipId,
    ): Collection;

    public function save(
        MembershipRole $membershipRole,
    ): MembershipRole;

    public function delete(
        MembershipRole $membershipRole,
    ): void;
}
