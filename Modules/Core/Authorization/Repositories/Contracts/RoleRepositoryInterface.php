<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Role;

interface RoleRepositoryInterface
{
    public function findById(
        string $id,
    ): ?Role;

    public function findByName(
        string $name,
    ): ?Role;

    /**
     * @return Collection<int, Role>
     */
    public function all(): Collection;

    public function exists(
        string $id,
    ): bool;

    public function save(
        Role $role,
    ): Role;

    public function delete(
        Role $role,
    ): void;
}
