<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Permission;

interface PermissionRepositoryInterface
{
    public function findById(
        string $id,
    ): ?Permission;

    public function findByName(
        string $name,
    ): ?Permission;

    /**
     * @return Collection<int, Permission>
     */
    public function all(): Collection;

    public function exists(
        string $id,
    ): bool;

    public function save(
        Permission $permission,
    ): Permission;

    public function delete(
        Permission $permission,
    ): void;
}
