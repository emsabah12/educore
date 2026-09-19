<?php

declare(strict_types=1);

namespace Modules\HR\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

/**
 * HR-004 §16 — role sistem/global baseline untuk kapabilitas
 * self-service Cuti, menutup gap yang sengaja dicatat di
 * {@see HrAuthorizationCatalogSeeder}: `hr.leave.self.*` dikecualikan
 * dari auto-grant `hr-officer` karena secara konsep itu hak SETIAP
 * pegawai, bukan staf administrasi HR — role `hr-officer` yang sudah
 * ada tidak cocok mewakili "setiap pegawai".
 *
 * Role `employee` di sini SENGAJA dibuat generik (bukan
 * `hr-leave-self-service` atau sejenisnya) supaya bisa menampung
 * permission self-service modul lain di masa depan tanpa perlu role
 * baru lagi setiap kali ada kapabilitas self-service tambahan.
 *
 * Idempoten dan module-owned (tidak bergantung pada urutan seeder
 * lain — permission row dipastikan ada sendiri lewat updateOrCreate),
 * sesuai ADR-016, pola sama persis dengan HrAuthorizationCatalogSeeder.
 *
 * Auto-assign konkret role ini ke Membership pegawai baru terjadi di
 * WorkspaceEmployeeProvisioningService (Langkah 2), dan untuk pegawai
 * yang sudah ada sebelumnya lewat command backfill terpisah
 * (Langkah 3) — seeder ini HANYA memastikan role dan grant-nya ada,
 * tidak meng-assign ke Membership manapun.
 */
final class EmployeeSelfServiceRoleSeeder extends Seeder
{
    public const EMPLOYEE_ROLE = 'employee';

    /**
     * Definisi permission di sini SENGAJA diduplikasi persis dari
     * {@see HrAuthorizationCatalogSeeder::RESOURCE_PERMISSIONS} untuk
     * dua baris `hr.leave.self.*` (bukan mereferensikan konstanta
     * seeder lain) — modul ini harus tetap benar walau dijalankan
     * sendirian, tanpa bergantung pada isi internal seeder lain.
     *
     * @var array<string, string>
     */
    private const SELF_SERVICE_PERMISSIONS = [
        'hr.leave.self.read' => 'View own Leave/Permit balance and request history (self-service)',
        'hr.leave.self.request' => 'Submit/withdraw own Leave/Permit request (self-service)',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $employeeRole = Role::query()->updateOrCreate(
                [
                    'tenant_id' => null,
                    'name' => self::EMPLOYEE_ROLE,
                ],
                [
                    'display_name' => 'Pegawai',
                    'description' => 'Baseline role for every provisioned Employee — grants self-service capabilities over the Employee\'s own records.',
                ],
            );

            foreach (self::SELF_SERVICE_PERMISSIONS as $name => $displayName) {
                $permission = Permission::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $displayName,
                        'description' => sprintf(
                            'Auto-provisioned canonical permission for %s.',
                            $name,
                        ),
                        'module' => 'HR',
                    ],
                );

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => (string) $employeeRole->getKey(),
                    'permission_id' => (string) $permission->getKey(),
                ]);
            }
        });
    }
}
