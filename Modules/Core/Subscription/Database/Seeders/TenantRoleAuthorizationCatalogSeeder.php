<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

/**
 * §PRD Subscription & Custom Role — "yang berwenang terhadap role
 * kustom tenant adalah owner, admin, dan tim ops dari tenant." Ini
 * SENGAJA diwujudkan sebagai PERMISSION (`tenant.custom-roles.manage`),
 * BUKAN dengan menghardcode nama role "owner"/"ops" di mana pun —
 * tenant bebas memberi permission ini ke role kustom apa pun yang
 * mereka buat sendiri (mis. "Owner", "Kepala Ops"), memakai mekanisme
 * yang sama seperti seluruh sistem otorisasi ini.
 *
 * Default: diberikan ke role sistem `admin` supaya SETIAP tenant yang
 * sudah ada langsung bisa memakai fitur ini tanpa konfigurasi manual.
 */
final class TenantRoleAuthorizationCatalogSeeder extends Seeder
{
    private const PERMISSION_NAME = 'tenant.custom-roles.manage';

    public function run(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->updateOrCreate(
                ['name' => self::PERMISSION_NAME],
                [
                    'display_name' => 'Kelola Role Kustom Tenant',
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
