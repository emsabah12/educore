<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Queries;

use Modules\Core\Authorization\Models\Role;

final class RoleCatalogQuery
{
    /**
     * §Perbaikan pasca-Step D: `roles` sekarang JUGA menyimpan role
     * KUSTOM milik tenant tertentu (`tenant_id` terisi). Endpoint ini
     * dipanggil member tenant MANA PUN untuk menemukan role GLOBAL
     * yang bisa mereka pakai (mis. saat mengundang staf baru) — TANPA
     * `whereNull('tenant_id')`, query ini akan membocorkan role
     * kustom milik SEMUA tenant lain ke tenant mana pun yang
     * memanggilnya.
     *
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
            ->whereNull('tenant_id')
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
