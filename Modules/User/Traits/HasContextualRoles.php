<?php

declare(strict_types=1);

namespace Modules\User\Traits;

use Illuminate\Support\Facades\DB;

trait HasContextualRoles
{
    /**
     * Mengecek apakah user memiliki role tertentu dalam sebuah membership/tenant spesifik.
     *
     * @param string $roleName Nama role (misal: 'admin')
     * @param string $membershipId ID membership user di tenant tersebut
     */
    public function hasRoleInMembership(string $roleName, string $membershipId): bool
    {
        return (bool) DB::table('membership_roles')
            ->join('roles', 'membership_roles.role_id', '=', 'roles.id')
            ->where('membership_roles.membership_id', $membershipId)
            ->where('roles.name', $roleName)
            ->exists();
    }

    /**
     * Mengecek apakah user memiliki permission tertentu dalam sebuah membership/tenant spesifik.
     */
    public function hasPermissionInMembership(string $permissionName, string $membershipId): bool
    {
        return (bool) DB::table('membership_roles')
            ->join('role_permissions', 'membership_roles.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('membership_roles.membership_id', $membershipId)
            ->where('permissions.name', $permissionName)
            ->exists();
    }
}
