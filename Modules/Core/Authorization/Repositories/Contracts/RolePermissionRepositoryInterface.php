<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

interface RolePermissionRepositoryInterface
{
    /**
     * Memeriksa apakah global canonical role mempunyai
     * global canonical permission tertentu.
     */
    public function roleHasPermission(
        string $roleId,
        string $permissionName,
    ): bool;
}
