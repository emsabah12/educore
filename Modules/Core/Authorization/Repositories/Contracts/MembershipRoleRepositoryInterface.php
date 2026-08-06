<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Role;

interface MembershipRoleRepositoryInterface
{
    /**
     * Mengambil seluruh global canonical role milik membership aktif
     * dalam tenant tertentu.
     *
     * @return Collection<int, Role>
     */
    public function rolesForMembership(
        string $membershipId,
        string $tenantId,
    ): Collection;

    /**
     * Memeriksa apakah membership aktif dalam tenant tertentu
     * memiliki global canonical role.
     */
    public function membershipHasRole(
        string $membershipId,
        string $tenantId,
        string $roleName,
    ): bool;

    /**
     * Menetapkan global canonical role kepada membership aktif
     * dalam tenant tertentu.
     */
    public function assignRole(
        string $membershipId,
        string $tenantId,
        string $roleId,
    ): void;
}
