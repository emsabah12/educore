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
        'hr.onboarding.view' => 'View Onboarding Templates, Cases, and Tasks',
        'hr.onboarding.manage' => 'Create/update non-final Onboarding lifecycle data',
        'hr.onboarding.activate' => 'Higher-impact Onboarding operations (waive a required task now; Employment Activation orchestration later, HR-003 §13)',

        // HR-004 §16 — Leave & Permit System.
        //
        // `hr.leave.self.*` SENGAJA TIDAK di-auto-grant ke hr-officer di
        // sini — kapabilitas self-service secara konseptual milik SETIAP
        // Employee (via membership mereka sendiri), bukan cuma staf HR.
        // Katalog ini tetap MENDAFTARKAN nama permission-nya (supaya ada
        // baris `permissions` yang valid untuk dirujuk role lain), tapi
        // grant konkretnya menyusul lewat mekanisme provisioning
        // Employee/Membership terpisah — belum ada di HR-004 Phase 2C.
        'hr.leave.self.read' => 'View own Leave/Permit balance and request history (self-service)',
        'hr.leave.self.request' => 'Submit/withdraw own Leave/Permit request (self-service)',
        'hr.leave.read' => 'View Leave/Permit requests tenant-wide or within authorized organizational scope',
        'hr.leave.manage' => 'Create/update non-final Leave/Permit request data on behalf of an Employee',
        'hr.leave.approve' => 'Approve/reject a Leave/Permit request approval step',
        'hr.leave.cancel' => 'Cancel an APPROVED Leave/Permit request (higher-impact operation)',
        'hr.leave.policy.read' => 'View Leave Type, Entitlement Policy, and Approval Policy configuration',
        'hr.leave.policy.manage' => 'Create/update Leave Type, Entitlement Policy, and Approval Policy configuration',
        'hr.leave.balance.read' => 'View Entitlement balance for any Employee within authorized scope',
        'hr.leave.balance.adjust' => 'Manually adjust Entitlement balance (higher-impact operation, always audited)',

        // HR-006 §7.2 — Compensation Component catalog.
        'hr.compensation.components.view' => 'View Compensation Component catalog',
        'hr.compensation.components.manage' => 'Create/update Compensation Component catalog',

        // HR-006 §7.3 — Compensation Assignment lifecycle.
        'hr.compensation.assignments.view' => 'View Compensation Assignment records for an Employment',
        'hr.compensation.assignments.manage' => 'Create draft / end Compensation Assignment records',
        'hr.compensation.assignments.approve' => 'Approve or correct a Compensation Assignment (higher-impact operation)',
    ];

    /**
     * `hr.leave.self.*` dikecualikan dari auto-grant hr-officer — lihat
     * komentar di RESOURCE_PERMISSIONS.
     *
     * @var list<string>
     */
    private const SELF_SERVICE_PERMISSIONS = [
        'hr.leave.self.read',
        'hr.leave.self.request',
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

                if (in_array($name, self::SELF_SERVICE_PERMISSIONS, true)) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => (string) $hrOfficerRole->getKey(),
                    'permission_id' => (string) $permission->getKey(),
                ]);
            }
        });
    }
}
