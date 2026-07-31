<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\RolePermission;

interface RolePermissionRepositoryInterface
{
    /**
     * @return Collection<int, Permission>
     */
    public function permissionsForRole(
        string $roleId,
    ): Collection;

    public function roleHasPermission(
        string $roleId,
        string $permissionName,
    ): bool;

    /**
     * @return Collection<int, RolePermission>
     */
    public function findByRole(
        string $roleId,
    ): Collection;

    public function save(
        RolePermission $rolePermission,
    ): RolePermission;

    public function delete(
        RolePermission $rolePermission,
    ): void;
}
