<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentRolePermissionRepository implements RolePermissionRepositoryInterface
{
    public function roleHasPermission(
        string $roleId,
        string $permissionName,
    ): bool {
        $roleId = trim($roleId);
        $permissionName = trim($permissionName);

        if (
            ! UuidV7::validate($roleId)
            || $permissionName === ''
        ) {
            return false;
        }

        return Permission::query()
            ->join(
                'role_permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id',
            )
            ->where(
                'role_permissions.role_id',
                $roleId,
            )
            ->where(
                'permissions.name',
                $permissionName,
            )
            ->exists();
    }
}
