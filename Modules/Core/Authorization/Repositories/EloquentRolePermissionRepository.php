<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\RolePermission;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;

final class EloquentRolePermissionRepository implements RolePermissionRepositoryInterface
{
    /**
     * @return Collection<int, Permission>
     */
    public function permissionsForRole(
        string $roleId,
    ): Collection {
        return Permission::query()
            ->select('permissions.*')
            ->join(
                'role_permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id'
            )
            ->where(
                'role_permissions.role_id',
                $roleId
            )
            ->orderBy('permissions.name')
            ->get();
    }

    public function roleHasPermission(
        string $roleId,
        string $permissionName,
    ): bool {
        return Permission::query()
            ->join(
                'role_permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id'
            )
            ->where(
                'role_permissions.role_id',
                $roleId
            )
            ->where(
                'permissions.name',
                $permissionName
            )
            ->exists();
    }

    /**
     * @return Collection<int, RolePermission>
     */
    public function findByRole(
        string $roleId,
    ): Collection {
        return RolePermission::query()
            ->where(
                'role_id',
                $roleId
            )
            ->orderBy('permission_id')
            ->get();
    }

    public function save(
        RolePermission $rolePermission,
    ): RolePermission {
        $rolePermission->save();

        return $rolePermission;
    }

    public function delete(
        RolePermission $rolePermission,
    ): void {
        $rolePermission->delete();
    }
}
