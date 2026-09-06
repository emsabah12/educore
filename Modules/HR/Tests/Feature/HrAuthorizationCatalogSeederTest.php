<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HrAuthorizationCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, string>>
     */
    public static function resourcePermissionProvider(): array
    {
        return [
            'employees view' => ['hr.employees.view'],
            'employees create' => ['hr.employees.create'],
        ];
    }

    #[DataProvider('resourcePermissionProvider')]
    public function test_seeder_creates_expected_permission(string $permissionName): void
    {
        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => $permissionName,
            'module' => 'HR',
        ]);
    }

    public function test_seeder_creates_hr_officer_role_with_full_resource_permission_catalog(): void
    {
        $this->seed(HrAuthorizationCatalogSeeder::class);

        $hrOfficer = Role::query()
            ->where('name', HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE)
            ->sole();

        $this->assertSame('HR Officer', $hrOfficer->display_name);

        $grantedPermissionNames = $hrOfficer->permissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [
                'hr.employees.create',
                'hr.employees.view',
                'hr.employments.end',
                'hr.employments.manage',
                'hr.employments.view',
                'hr.leave.approve',
                'hr.leave.balance.adjust',
                'hr.leave.balance.read',
                'hr.leave.cancel',
                'hr.leave.manage',
                'hr.leave.policy.manage',
                'hr.leave.policy.read',
                'hr.leave.read',
                'hr.onboarding.activate',
                'hr.onboarding.manage',
                'hr.onboarding.view',
                'hr.recruitment.approve',
                'hr.recruitment.manage',
                'hr.recruitment.view',
            ],
            $grantedPermissionNames,
        );
    }

    public function test_seeder_creates_leave_self_service_permissions_without_granting_them_to_hr_officer(): void
    {
        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => 'hr.leave.self.read',
            'module' => 'HR',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'hr.leave.self.request',
            'module' => 'HR',
        ]);

        $hrOfficer = Role::query()
            ->where('name', HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE)
            ->sole();

        $grantedPermissionNames = $hrOfficer->permissions()
            ->pluck('name')
            ->all();

        $this->assertNotContains('hr.leave.self.read', $grantedPermissionNames);
        $this->assertNotContains('hr.leave.self.request', $grantedPermissionNames);
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $this->seed(HrAuthorizationCatalogSeeder::class);
        $this->seed(HrAuthorizationCatalogSeeder::class);
        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->assertSame(
            1,
            Role::query()
                ->where('name', HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE)
                ->count(),
        );

        $this->assertSame(
            1,
            Permission::query()
                ->where('name', 'hr.employees.create')
                ->count(),
        );
    }

    public function test_seeder_preserves_existing_role_id_across_reseed(): void
    {
        $this->seed(HrAuthorizationCatalogSeeder::class);

        $originalId = (string) Role::query()
            ->where('name', HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE)
            ->sole()
            ->getKey();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $reseededId = (string) Role::query()
            ->where('name', HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE)
            ->sole()
            ->getKey();

        $this->assertSame($originalId, $reseededId);
    }
}
