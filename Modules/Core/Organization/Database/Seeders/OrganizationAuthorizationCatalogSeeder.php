<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

/**
 * Mengelola Organisasi (membuat struktur organisasi tenant) adalah
 * tindakan level-TENANT — bukan level-organisasi (organisasi itu
 * sendiri belum ada sebelum dibuat, jadi tidak bisa digate lewat
 * `organizational.permission`). Pola sama persis dengan
 * `TenantRoleAuthorizationCatalogSeeder`: permission baru + diberikan
 * ke role sistem `admin` secara default supaya SETIAP tenant yang
 * sudah ada langsung bisa memakai fitur ini tanpa konfigurasi manual.
 */
final class OrganizationAuthorizationCatalogSeeder extends Seeder
{
    private const PERMISSION_NAME = 'organization.manage';

    public function run(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->updateOrCreate(
                ['name' => self::PERMISSION_NAME],
                [
                    'display_name' => 'Kelola Organisasi',
                    'module' => 'Core',
                ],
            );

            $adminRole = Role::query()
                ->whereNull('tenant_id')
                ->where('name', 'admin')
                ->first();

            if ($adminRole === null) {
                return;
            }

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $adminRole->id,
                'permission_id' => $permission->id,
            ]);
        });
    }
}
