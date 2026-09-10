<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

/**
 * Mengelola Organisasi/Unit (membuat struktur organisasi tenant)
 * adalah tindakan level-TENANT — bukan level-organisasi (Organization
 * itu sendiri belum tentu sudah ada/lengkap sebelum diubah, jadi
 * tidak bisa digate lewat `organizational.permission`). Pola sama
 * persis dengan `TenantRoleAuthorizationCatalogSeeder`: permission
 * baru + diberikan ke role sistem `admin` secara default supaya
 * SETIAP tenant yang sudah ada langsung bisa memakai fitur ini tanpa
 * konfigurasi manual.
 *
 * `organization.units.manage` SENGAJA dipisah dari `organization.manage`
 * (bukan digabung jadi satu permission) — supaya ke depan bisa
 * didelegasikan secara granular (mis. staf tata usaha yang boleh atur
 * Unit tapi tidak boleh membuat Organization baru).
 */
final class OrganizationAuthorizationCatalogSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const RESOURCE_PERMISSIONS = [
        'organization.manage' => 'Kelola Organisasi',
        'organization.units.manage' => 'Kelola Unit Organisasi',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $adminRole = Role::query()
                ->whereNull('tenant_id')
                ->where('name', 'admin')
                ->first();

            foreach (self::RESOURCE_PERMISSIONS as $name => $displayName) {
                $permission = Permission::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $displayName,
                        'module' => 'Core',
                    ],
                );

                if ($adminRole === null) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $adminRole->id,
                    'permission_id' => $permission->id,
                ]);
            }
        });
    }
}
