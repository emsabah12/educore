<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Queries;

use Modules\Core\Authorization\Models\Role;

final class RoleCatalogQuery
{
    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     display_name: string,
     *     description: string|null
     * }>
     */
    public function execute(): array
    {
        return Role::query()
            ->select([
                'id',
                'name',
                'display_name',
                'description',
            ])
            ->orderBy('name')
            ->get()
            ->map(static fn (Role $role): array => [
                'id' => (string) $role->id,
                'name' => (string) $role->name,
                'display_name' => (string) $role->display_name,
                'description' => $role->description === null
                    ? null
                    : (string) $role->description,
            ])
            ->all();
    }
}
