<?php

declare(strict_types=1);

namespace Modules\HR\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

final class HrAuthorizationCatalogSeeder extends Seeder
{
    public const HR_OFFICER_ROLE = 'hr-officer';

    /**
     * Katalog permission HR.
     *
     * Nama mengikuti HR-013 — HR Authorization Matrix & Existing Route
     * Remediation (APPROVED/LOCKED), section "Workforce Permissions".
     *
     * Sengaja hanya `employees.view` dan `employees.create` untuk
     * sekarang (surface HR saat ini baru sebatas Employee list/create).
     * `hr.employees.update` dan `hr.employees.sensitive.view` belum
     * ditambahkan karena belum ada endpoint yang membutuhkannya —
     * akan ditambah saat endpoint terkait benar-benar dibangun
     * (Wave 1: Workforce/Employment).
     *
     * @var array<string, string>
     */
    private const RESOURCE_PERMISSIONS = [
        'hr.employees.view' => 'View Employee directory/profile',
        'hr.employees.create' => 'Create/provision Employee',
    ];

    /**
     * Ensure the HR authorization catalog exists.
     *
     * Idempoten dan module-owned (tidak bergantung pada seeder module
     * lain), sesuai ADR-016: "Module owns concrete capability catalog".
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $hrOfficerRole = Role::query()->updateOrCreate(
                ['name' => self::HR_OFFICER_ROLE],
                [
                    'display_name' => 'HR Officer',
                    'description' => 'Human resources administrative staff (employee records).',
                ],
            );

            foreach (self::RESOURCE_PERMISSIONS as $name => $displayName) {
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
                    'role_id' => (string) $hrOfficerRole->getKey(),
                    'permission_id' => (string) $permission->getKey(),
                ]);
            }
        });
    }
}
