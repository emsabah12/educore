<?php

namespace Modules\Core\Application\Authorization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Authorization\Permission;
use Modules\Core\Domain\Authorization\Role;

/**
 * Menyalin AccessCatalog (kode) ke tabel permissions, roles, role_permission (OD-11).
 *
 * Aman dijalankan berulang dan di production (dipanggil saat deploy):
 * - permission baru ditambahkan, nama yang berubah diperbarui;
 * - permission yang sudah tidak ada di kode dihapus (ikut hilang dari semua role);
 * - isi role disamakan persis dengan definisi di kode;
 * - role yang tidak ada di kode TIDAK dihapus (mungkin masih dipasang ke orang), tetapi
 *   isinya dikosongkan sehingga tidak lagi memberi hak akses apa pun.
 */
final class SyncAccessCatalog
{
    public function __construct(
        private readonly AccessCatalog $catalog,
    ) {}

    /**
     * @return array{permissions: int, roles: int, removed_permissions: int, emptied_roles: int}
     */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            $permissions = $this->catalog->permissions();

            foreach ($permissions as $key => $name) {
                Permission::query()->updateOrCreate(['key' => $key], ['name' => $name]);
            }

            $removed = Permission::query()->whereNotIn('key', array_keys($permissions))->delete();

            $roles = $this->catalog->roles();

            foreach ($roles as $key => $definition) {
                $role = Role::query()->updateOrCreate(['key' => $key], ['name' => $definition['name']]);

                $permissionIds = Permission::query()
                    ->whereIn('key', $definition['permissions'])
                    ->pluck('id')
                    ->all();

                $role->permissions()->sync($permissionIds);
            }

            $emptied = 0;

            foreach (Role::query()->whereNotIn('key', array_keys($roles))->get() as $orphan) {
                if ($orphan->permissions()->detach() > 0) {
                    $emptied++;
                }
            }

            return [
                'permissions' => count($permissions),
                'roles' => count($roles),
                'removed_permissions' => (int) $removed,
                'emptied_roles' => $emptied,
            ];
        });
    }
}
