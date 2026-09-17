<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

/**
 * §Kelola Anggota & Role — SENGAJA dijalankan PALING TERAKHIR di
 * DatabaseSeeder (setelah seluruh seeder katalog modul: Academic,
 * HR, Organization, dst.), supaya benar-benar menangkap SEMUA
 * permission yang sudah terdaftar, dari modul manapun.
 *
 * Ini secara sadar MEMBALIK prinsip least-privilege yang berlaku di
 * tempat lain pada authorization runtime ini (lihat
 * AuthorizationService::hasPermission(), yang TIDAK punya bypass
 * berbasis role) — atas permintaan eksplisit operator platform: role
 * 'admin' tenant harus otomatis memiliki seluruh permission yang
 * terdaftar, tanpa perlu di-assign satu per satu.
 *
 * Idempoten dan aman dijalankan ulang kapan pun — setiap kali
 * permission baru ditambahkan oleh seeder modul manapun di masa
 * depan, jalankan ulang seeder ini untuk menyalurkannya ke admin.
 */
final class AdminRoleFullAccessSeeder extends Seeder
{
    private const ADMIN_ROLE_NAME = 'admin';

    public function run(): void
    {
        DB::transaction(function (): void {
            $adminRole = Role::query()
                ->where('name', self::ADMIN_ROLE_NAME)
                ->first();

            if ($adminRole === null) {
                // AuthorizationCatalogSeeder belum dijalankan —
                // tidak ada apa pun untuk di-grant.
                return;
            }

            $permissionIds = Permission::query()
                ->pluck('id');

            if ($permissionIds->isEmpty()) {
                return;
            }

            $rows = $permissionIds
                ->map(
                    static fn (string $permissionId): array => [
                        'role_id' => $adminRole->id,
                        'permission_id' => $permissionId,
                    ],
                )
                ->all();

            DB::table('role_permissions')
                ->insertOrIgnore($rows);
        });
    }
}
