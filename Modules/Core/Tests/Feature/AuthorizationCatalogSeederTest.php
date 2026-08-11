<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class AuthorizationCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_only_minimal_admin_role_with_uuid_v7(): void
    {
        $this->seed(AuthorizationCatalogSeeder::class);

        $admin = Role::query()
            ->where('name', 'admin')
            ->sole();

        $this->assertTrue(UuidV7::validate((string) $admin->getKey()));
        $this->assertSame('Administrator', $admin->display_name);
        $this->assertSame('Tenant administrator role.', $admin->description);
        $this->assertSame(1, Role::query()->count());
        $this->assertSame(0, Permission::query()->count());
    }

    public function test_seeder_is_idempotent_preserves_role_id_and_restores_metadata(): void
    {
        $this->seed(AuthorizationCatalogSeeder::class);

        $admin = Role::query()
            ->where('name', 'admin')
            ->sole();

        $originalId = (string) $admin->getKey();

        $admin->forceFill([
            'display_name' => 'Locally Changed',
            'description' => 'Locally changed metadata.',
        ])->save();

        $this->seed(AuthorizationCatalogSeeder::class);

        $reseeded = Role::query()
            ->where('name', 'admin')
            ->sole();

        $this->assertSame($originalId, (string) $reseeded->getKey());
        $this->assertSame('Administrator', $reseeded->display_name);
        $this->assertSame('Tenant administrator role.', $reseeded->description);
        $this->assertSame(1, Role::query()->where('name', 'admin')->count());
    }

    public function test_seeder_keeps_custom_catalog_entries_and_does_not_seed_permissions(): void
    {
        $customRole = Role::query()->create([
            'name' => 'custom-operator',
            'display_name' => 'Custom Operator',
            'description' => 'Application-managed custom role.',
        ]);

        $customPermission = Permission::query()->create([
            'name' => 'custom.permission',
            'display_name' => 'Custom Permission',
            'description' => 'Application-managed custom permission.',
            'module' => 'Custom',
        ]);

        $this->seed(AuthorizationCatalogSeeder::class);

        $this->assertDatabaseHas('roles', [
            'id' => (string) $customRole->getKey(),
            'name' => 'custom-operator',
        ]);

        $this->assertDatabaseHas('permissions', [
            'id' => (string) $customPermission->getKey(),
            'name' => 'custom.permission',
        ]);

        $this->assertDatabaseHas('roles', [
            'name' => 'admin',
        ]);

        $this->assertSame(2, Role::query()->count());
        $this->assertSame(1, Permission::query()->count());
    }

    public function test_database_seeder_wires_authorization_catalog_bootstrap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('roles', [
            'name' => 'admin',
            'display_name' => 'Administrator',
            'description' => 'Tenant administrator role.',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    }
}
