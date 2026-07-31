<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

interface AccessCheckerInterface
{
    /**
     * Menentukan apakah user saat ini memiliki role tertentu
     * pada Authorization Context yang aktif.
     */
    public function hasRole(
        string $roleName,
    ): bool;

    /**
     * Menentukan apakah user saat ini memiliki permission tertentu
     * pada Authorization Context yang aktif.
     */
    public function can(
        string $permissionName,
    ): bool;
}
