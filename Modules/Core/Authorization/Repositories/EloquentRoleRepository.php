<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\RoleRepositoryInterface;

final class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function findById(string $id): ?Role
    {
        return Role::query()->find($id);
    }

    public function findByName(string $name): ?Role
    {
        return Role::query()
            ->where('name', $name)
            ->first();
    }

    public function all(): Collection
    {
        return Role::query()
            ->orderBy('name')
            ->get();
    }

    public function exists(string $id): bool
    {
        return Role::query()
            ->whereKey($id)
            ->exists();
    }

    public function save(Role $role): Role
    {
        $role->save();

        return $role;
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }
}
