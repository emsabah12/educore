<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Repositories\Contracts\PermissionRepositoryInterface;

final class EloquentPermissionRepository implements PermissionRepositoryInterface
{
    public function findById(
        string $id,
    ): ?Permission {
        return Permission::query()->find($id);
    }

    public function findByName(
        string $name,
    ): ?Permission {
        return Permission::query()
            ->where('name', $name)
            ->first();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function all(): Collection
    {
        return Permission::query()
            ->orderBy('name')
            ->get();
    }

    public function exists(
        string $id,
    ): bool {
        return Permission::query()
            ->whereKey($id)
            ->exists();
    }

    public function save(
        Permission $permission,
    ): Permission {
        $permission->save();

        return $permission;
    }

    public function delete(
        Permission $permission,
    ): void {
        $permission->delete();
    }
}
