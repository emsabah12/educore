<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\RolePermission;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Traits\BelongsToTenant;
use Tests\TestCase;

final class RolePermissionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_and_role_permission_are_global_entities(): void
    {
        $permissionTraits = class_uses_recursive(
            Permission::class,
        );

        $rolePermissionTraits = class_uses_recursive(
            RolePermission::class,
        );

        $this->assertNotContains(
            BelongsToTenant::class,
            $permissionTraits,
        );

        $this->assertNotContains(
            BelongsToTenant::class,
            $rolePermissionTraits,
        );

        $rolePermission = new RolePermission();

        $this->assertFalse(
            $rolePermission->usesTimestamps(),
        );
    }

    public function test_repository_checks_global_permission_without_tenant_context(): void
    {
        $roleId = $this->createRole(
            name: 'permission-test-role',
        );

        $grantedPermissionId = $this->createPermission(
            name: 'permission-test:granted',
        );

        $this->createPermission(
            name: 'permission-test:not-granted',
        );

        DB::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $grantedPermissionId,
        ]);

        $tenantContext = $this->app->make(
            TenantContextInterface::class,
        );

        $tenantContext->clear();

        $this->assertNull(
            $tenantContext->getCurrentTenantId(),
        );

        $repository = $this->app->make(
            RolePermissionRepositoryInterface::class,
        );

        $this->assertTrue(
            $repository->roleHasPermission(
                $roleId,
                'permission-test:granted',
            ),
        );

        $this->assertFalse(
            $repository->roleHasPermission(
                $roleId,
                'permission-test:not-granted',
            ),
        );

        $this->assertFalse(
            $repository->roleHasPermission(
                UuidV7::generate(),
                'permission-test:granted',
            ),
        );

        $this->assertFalse(
            $repository->roleHasPermission(
                '',
                'permission-test:granted',
            ),
        );

        $this->assertFalse(
            $repository->roleHasPermission(
                $roleId,
                '',
            ),
        );
    }

    public function test_composite_primary_key_prevents_duplicate_assignment(): void
    {
        $roleId = $this->createRole(
            name: 'duplicate-role',
        );

        $permissionId = $this->createPermission(
            name: 'duplicate:permission',
        );

        $firstInsertCount = DB::table(
            'role_permissions',
        )->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);

        $duplicateInsertCount = DB::table(
            'role_permissions',
        )->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);

        $this->assertSame(
            1,
            $firstInsertCount,
            'The initial role-permission assignment must be inserted.',
        );

        $this->assertSame(
            0,
            $duplicateInsertCount,
            'The composite primary key must reject the duplicate assignment.',
        );

        $this->assertSame(
            1,
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->count(),
        );
    }
    private function createRole(
        string $name,
    ): string {
        $roleId = UuidV7::generate();

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => $name,
            'display_name' => ucwords(
                str_replace(
                    ['-', ':'],
                    ' ',
                    $name,
                ),
            ),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    }

    private function createPermission(
        string $name,
    ): string {
        $permissionId = UuidV7::generate();

        DB::table('permissions')->insert([
            'id' => $permissionId,
            'name' => $name,
            'display_name' => ucwords(
                str_replace(
                    ['-', ':'],
                    ' ',
                    $name,
                ),
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $permissionId;
    }
}
