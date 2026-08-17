<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Queries\PermissionCatalogQuery;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class PermissionCatalogQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_canonical_permission_names_in_deterministic_order(): void
    {
        DB::table('permissions')->insert([
            $this->permissionData(
                name: 'zeta.operation.execute',
                displayName: 'Execute Zeta Operation',
                module: 'Zeta',
            ),
            $this->permissionData(
                name: 'academic.grades.write',
                displayName: 'Write Academic Grades',
                module: 'Academic',
            ),
            $this->permissionData(
                name: 'core.notifications.dispatch',
                displayName: 'Dispatch Notifications',
                module: 'Core',
            ),
        ]);

        $permissions = app(
            PermissionCatalogQuery::class,
        )->execute();

        $this->assertSame(
            [
                'academic.grades.write',
                'core.notifications.dispatch',
                'zeta.operation.execute',
            ],
            $permissions,
        );
    }

    public function test_it_returns_catalog_permissions_regardless_of_role_assignment(): void
    {
        $assignedPermissionId =
            UuidV7::generate();

        $unassignedPermissionId =
            UuidV7::generate();

        $roleId =
            UuidV7::generate();

        DB::table('permissions')->insert([
            $this->permissionData(
                id: $assignedPermissionId,
                name: 'catalog.assigned.permission',
                displayName: 'Assigned Catalog Permission',
                module: 'Core',
            ),
            $this->permissionData(
                id: $unassignedPermissionId,
                name: 'catalog.unassigned.permission',
                displayName: 'Unassigned Catalog Permission',
                module: 'Core',
            ),
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'catalog-test-role',
            'display_name' =>
            'Catalog Test Role',
            'description' =>
            'Role used only by permission catalog test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_permissions')->insert([
            'role_id' =>
            $roleId,
            'permission_id' =>
            $assignedPermissionId,
        ]);

        $permissions = app(
            PermissionCatalogQuery::class,
        )->execute();

        $this->assertSame(
            [
                'catalog.assigned.permission',
                'catalog.unassigned.permission',
            ],
            $permissions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionData(
        string $name,
        string $displayName,
        string $module,
        ?string $id = null,
    ): array {
        return [
            'id' =>
            $id ?? UuidV7::generate(),
            'name' =>
            $name,
            'display_name' =>
            $displayName,
            'description' =>
            null,
            'module' =>
            $module,
            'created_at' =>
            now(),
            'updated_at' =>
            now(),
        ];
    }
}
