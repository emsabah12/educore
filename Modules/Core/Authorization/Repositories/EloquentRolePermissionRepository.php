<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;

final class EloquentRolePermissionRepository implements RolePermissionRepositoryInterface
{
    public function roleHasPermission(
        string $roleId,
        string $permissionName,
    ): bool {
        $roleId = trim($roleId);
        $permissionName = trim($permissionName);

        if ($roleId === '' || $permissionName === '') {
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
