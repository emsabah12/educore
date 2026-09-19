<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\HR\Database\Seeders\EmployeeSelfServiceRoleSeeder;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Tests\TestCase;

final class EmployeeSelfServiceRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_employee_role_as_global_system_role(): void
    {
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $employeeRole = Role::query()
            ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
            ->sole();

        $this->assertNull($employeeRole->tenant_id);
        $this->assertSame('Pegawai', $employeeRole->display_name);
    }

    public function test_seeder_creates_both_self_service_leave_permissions(): void
    {
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => 'hr.leave.self.read',
            'module' => 'HR',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'hr.leave.self.request',
            'module' => 'HR',
        ]);
    }

    public function test_seeder_grants_both_self_service_leave_permissions_to_employee_role(): void
    {
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $employeeRole = Role::query()
            ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
            ->sole();

        $grantedPermissionNames = $employeeRole->permissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [
                'hr.leave.self.read',
                'hr.leave.self.request',
            ],
            $grantedPermissionNames,
        );
    }

    public function test_seeder_works_standalone_without_hr_authorization_catalog_seeder_running_first(): void
    {
        // Sengaja TIDAK memanggil HrAuthorizationCatalogSeeder di sini
        // — membuktikan seeder ini benar-benar module-owned dan tidak
        // diam-diam bergantung pada urutan seeder lain (ADR-016).
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => 'hr.leave.self.read',
        ]);

        $employeeRole = Role::query()
            ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
            ->sole();

        $this->assertSame(
            2,
            $employeeRole->permissions()->count(),
        );
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $this->seed(EmployeeSelfServiceRoleSeeder::class);
        $this->seed(EmployeeSelfServiceRoleSeeder::class);
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $this->assertSame(
            1,
            Role::query()
                ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
                ->count(),
        );

        $this->assertSame(
            1,
            Permission::query()
                ->where('name', 'hr.leave.self.read')
                ->count(),
        );

        $employeeRole = Role::query()
            ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
            ->sole();

        $this->assertSame(
            2,
            $employeeRole->permissions()->count(),
        );
    }

    public function test_seeder_preserves_existing_role_id_across_reseed(): void
    {
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $originalId = (string) Role::query()
            ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
            ->sole()
            ->getKey();

        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $reseededId = (string) Role::query()
            ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
            ->sole()
            ->getKey();

        $this->assertSame($originalId, $reseededId);
    }

    public function test_seeder_is_consistent_with_hr_authorization_catalog_seeder_when_both_run(): void
    {
        // Kalau kedua seeder jalan (urutan produksi sesungguhnya di
        // DatabaseSeeder), permission row-nya harus tetap SATU baris
        // yang sama (updateOrCreate mengunci berdasarkan `name`),
        // bukan dua baris yang saling menimpa.
        $this->seed(HrAuthorizationCatalogSeeder::class);
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $this->assertSame(
            1,
            Permission::query()
                ->where('name', 'hr.leave.self.read')
                ->count(),
        );

        $this->assertSame(
            1,
            Permission::query()
                ->where('name', 'hr.leave.self.request')
                ->count(),
        );

        $hrOfficer = Role::query()
            ->where('name', HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE)
            ->sole();

        $hrOfficerPermissionNames = $hrOfficer->permissions()
            ->pluck('name')
            ->all();

        // hr-officer tetap TIDAK dapat kedua permission ini —
        // memastikan seeder baru tidak diam-diam mengubah perilaku
        // seeder lama.
        $this->assertNotContains('hr.leave.self.read', $hrOfficerPermissionNames);
        $this->assertNotContains('hr.leave.self.request', $hrOfficerPermissionNames);

        $employeeRole = Role::query()
            ->where('name', EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE)
            ->sole();

        $employeePermissionNames = $employeeRole->permissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [
                'hr.leave.self.read',
                'hr.leave.self.request',
            ],
            $employeePermissionNames,
        );
    }
}
