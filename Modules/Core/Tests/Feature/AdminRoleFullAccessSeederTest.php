<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Database\Seeders\AdminRoleFullAccessSeeder;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Tests\TestCase;

final class AdminRoleFullAccessSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_every_registered_permission_to_the_admin_role(): void
    {
        $this->seed(AuthorizationCatalogSeeder::class);

        $adminRole = Role::query()
            ->where('name', 'admin')
            ->firstOrFail();

        $permissionA = Permission::query()->create([
            'name' => 'test.permission.alpha',
            'display_name' => 'Alpha',
            'description' => null,
            'module' => 'Core',
        ]);

        $permissionB = Permission::query()->create([
            'name' => 'test.permission.beta',
            'display_name' => 'Beta',
            'description' => null,
            'module' => 'Core',
        ]);

        $this->seed(AdminRoleFullAccessSeeder::class);

        $grantedPermissionNames = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $adminRole->id)
            ->pluck('permissions.name')
            ->all();

        $this->assertContains(
            $permissionA->name,
            $grantedPermissionNames,
        );

        $this->assertContains(
            $permissionB->name,
            $grantedPermissionNames,
        );
    }

    public function test_it_is_idempotent_and_does_not_duplicate_rows_or_error_on_reseed(): void
    {
        $this->seed(AuthorizationCatalogSeeder::class);

        Permission::query()->create([
            'name' => 'test.permission.gamma',
            'display_name' => 'Gamma',
            'description' => null,
            'module' => 'Core',
        ]);

        $this->seed(AdminRoleFullAccessSeeder::class);
        $this->seed(AdminRoleFullAccessSeeder::class);

        $adminRole = Role::query()
            ->where('name', 'admin')
            ->firstOrFail();

        $rowCount = DB::table('role_permissions')
            ->where('role_id', $adminRole->id)
            ->where(
                'permission_id',
                Permission::query()
                    ->where('name', 'test.permission.gamma')
                    ->value('id'),
            )
            ->count();

        $this->assertSame(
            1,
            $rowCount,
        );
    }

    public function test_it_does_nothing_when_admin_role_does_not_exist_yet(): void
    {
        Permission::query()->create([
            'name' => 'test.permission.delta',
            'display_name' => 'Delta',
            'description' => null,
            'module' => 'Core',
        ]);

        // AuthorizationCatalogSeeder (yang membuat role admin) SENGAJA
        // tidak dijalankan di sini — memverifikasi seeder ini gagal
        // closed, bukan melempar exception, saat dijalankan di luar
        // urutan.
        $this->seed(AdminRoleFullAccessSeeder::class);

        $this->assertSame(
            0,
            DB::table('role_permissions')->count(),
        );
    }
}
