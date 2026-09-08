<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class TenantScopedRolePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_can_be_created_without_tenant_id_as_global_role(): void
    {
        $role = Role::query()->create([
            'name' => 'global-role-uji',
            'display_name' => 'Global Role Uji',
        ]);

        $this->assertNull($role->tenant_id);
    }

    public function test_role_can_be_created_with_tenant_id_as_custom_role(): void
    {
        $tenant = $this->createTenant('tenant-custom-role-uji');

        $role = Role::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'wali-kelas',
            'display_name' => 'Wali Kelas',
        ]);

        $this->assertSame($tenant->id, $role->tenant_id);
    }

    public function test_two_global_roles_cannot_share_the_same_name(): void
    {
        Role::query()->create(['name' => 'dup-global-role', 'display_name' => 'A']);

        $this->expectException(QueryException::class);

        Role::query()->create(['name' => 'dup-global-role', 'display_name' => 'B']);
    }

    public function test_two_different_tenants_can_have_custom_roles_with_the_same_name(): void
    {
        $tenantA = $this->createTenant('tenant-same-name-a');
        $tenantB = $this->createTenant('tenant-same-name-b');

        Role::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'wali-kelas',
            'display_name' => 'Wali Kelas A',
        ]);

        $roleB = Role::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'wali-kelas',
            'display_name' => 'Wali Kelas B',
        ]);

        $this->assertSame($tenantB->id, $roleB->tenant_id);
    }

    public function test_same_tenant_cannot_have_two_custom_roles_with_the_same_name(): void
    {
        $tenant = $this->createTenant('tenant-dup-custom-role');

        Role::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'wali-kelas',
            'display_name' => 'Wali Kelas 1',
        ]);

        $this->expectException(QueryException::class);

        Role::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'wali-kelas',
            'display_name' => 'Wali Kelas 2',
        ]);
    }

    public function test_custom_role_is_removed_when_owning_tenant_is_hard_deleted(): void
    {
        $tenant = $this->createTenant('tenant-cascade-role');

        $role = Role::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'cascade-role',
            'display_name' => 'Cascade Role',
        ]);

        $tenant->forceDelete();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    private function createTenant(string $subdomain): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Tenant ' . $subdomain,
            'subdomain' => $subdomain,
            'is_active' => true,
        ]);
    }
}
