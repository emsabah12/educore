<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Subscription\Database\Seeders\TenantRoleAuthorizationCatalogSeeder;
use Tests\TestCase;

final class TenantRoleAuthorizationCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_permission_and_grants_it_to_admin(): void
    {
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(TenantRoleAuthorizationCatalogSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'tenant.custom-roles.manage']);

        $adminRole = Role::query()->whereNull('tenant_id')->where('name', 'admin')->firstOrFail();
        $permission = Permission::query()->where('name', 'tenant.custom-roles.manage')->firstOrFail();

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $adminRole->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(TenantRoleAuthorizationCatalogSeeder::class);
        $this->seed(TenantRoleAuthorizationCatalogSeeder::class);

        $this->assertSame(
            1,
            Permission::query()->where('name', 'tenant.custom-roles.manage')->count(),
        );
    }

    public function test_seeder_does_nothing_harmful_when_admin_role_does_not_exist_yet(): void
    {
        // Sengaja TIDAK menjalankan AuthorizationCatalogSeeder dulu.
        $this->seed(TenantRoleAuthorizationCatalogSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'tenant.custom-roles.manage']);
        $this->assertDatabaseMissing('role_permissions', [
            'permission_id' => Permission::query()->where('name', 'tenant.custom-roles.manage')->value('id'),
        ]);
    }
}
