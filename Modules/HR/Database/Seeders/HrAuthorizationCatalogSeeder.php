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
     * `hr.employments.end` dipisah dari `hr.employments.manage` karena
     * HR-013-BR-002 menandainya sebagai higher-impact operation —
     * mengakhiri hubungan kerja adalah tindakan yang jauh lebih serius
     * daripada sekadar membuat/mengaktifkan Employment.
     *
     * @var array<string, string>
     */
    private const RESOURCE_PERMISSIONS = [
        'hr.employees.view' => 'View Employee directory/profile',
        'hr.employees.create' => 'Create/provision Employee',
        'hr.employments.view' => 'View Employment history',
        'hr.employments.manage' => 'Create/update non-final Employment lifecycle data',
        'hr.employments.end' => 'End an active Employment (higher-impact operation)',
        'hr.recruitment.view' => 'View Vacancy, Application, and Candidate records',
        'hr.recruitment.manage' => 'Create/update non-final Recruitment lifecycle data',
        'hr.recruitment.approve' => 'Approve/reject a Vacancy (higher-impact operation)',

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
