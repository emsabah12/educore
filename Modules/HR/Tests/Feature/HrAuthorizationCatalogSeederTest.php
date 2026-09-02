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

    public function test_seeder_creates_hr_officer_role_with_view_and_create_access(): void
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
            ],
            $grantedPermissionNames,
        );
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
