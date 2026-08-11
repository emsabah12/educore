<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class RbacModelPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_model_generates_uuid_v7_and_persists_canonical_fields(): void
    {
        $role = Role::query()->create([
            'name' => 'model-role',
            'display_name' => 'Model Role',
            'description' => 'Canonical global role.',
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $role->getKey()),
        );

        $this->assertDatabaseHas('roles', [
            'id' => (string) $role->getKey(),
            'name' => 'model-role',
            'display_name' => 'Model Role',
            'description' => 'Canonical global role.',
        ]);
    }

    public function test_permission_model_generates_uuid_v7_and_persists_canonical_fields(): void
    {
        $permission = Permission::query()->create([
            'name' => 'model.permission',
            'display_name' => 'Model Permission',
            'description' => 'Canonical global permission.',
            'module' => 'Core',
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $permission->getKey()),
        );

        $this->assertDatabaseHas('permissions', [
            'id' => (string) $permission->getKey(),
            'name' => 'model.permission',
            'display_name' => 'Model Permission',
            'description' => 'Canonical global permission.',
            'module' => 'Core',
        ]);
    }
}
