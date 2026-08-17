<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class CapabilityProjectionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $personId;

    private string $userId;

    private string $membershipId;

    private string $organizationId;

    private string $assignmentId;

    private string $tenantRoleId;

    private string $workspaceRoleId;

    private string $tenantPermissionId;

    private string $workspacePermissionId;

    private string $deniedPermissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId =
            UuidV7::generate();

        $this->personId =
            UuidV7::generate();

        $this->userId =
            UuidV7::generate();

        $this->membershipId =
            UuidV7::generate();

        $this->organizationId =
            UuidV7::generate();

        $this->assignmentId =
            UuidV7::generate();

        $this->tenantRoleId =
            UuidV7::generate();

        $this->workspaceRoleId =
            UuidV7::generate();

        $this->tenantPermissionId =
            UuidV7::generate();

        $this->workspacePermissionId =
            UuidV7::generate();

        $this->deniedPermissionId =
            UuidV7::generate();

        $this->createFixture();
    }

    public function test_tenant_capability_endpoint_projects_only_effective_tenant_permissions(): void
    {
        $response = $this
            ->withToken(
                $this->issueToken(),
            )
            ->getJson(
                '/api/v1/core/authorization/capabilities',
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.scope.type',
                'tenant',
            )
            ->assertJsonPath(
                'data.scope.tenant_id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.scope.membership_id',
                $this->membershipId,
            )
            ->assertJsonPath(
                'data.is_global_superadmin',
                false,
            )
            ->assertJsonPath(
                'data.permissions.0',
                'academic.grades.write',
            )
            ->assertJsonCount(
                1,
                'data.permissions',
            );

        $this->assertNotContains(
            'dormitory.rooms.manage',
            $response->json(
                'data.permissions',
            ),
        );

        $this->assertNotContains(
            'dormitory.rooms.view',
            $response->json(
                'data.permissions',
            ),
        );

        $this->assertNotContains(
            'dormitory.rooms.manage',
            $response->json(
                'data.permissions',
            ),
        );

        $this->assertNotContains(
            'dormitory.rooms.view',
            $response->json(
                'data.permissions',
            ),
        );
    }

    public function test_workspace_capability_endpoint_projects_tenant_and_scoped_permissions(): void
    {
        $response = $this
            ->withToken(
                $this->issueToken(),
            )
            ->withHeader(
                'X-EduCore-Organizational-Assignment-Id',
                $this->assignmentId,
            )
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.scope.type',
                'organization',
            )
            ->assertJsonPath(
                'data.scope.tenant_id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.scope.membership_id',
                $this->membershipId,
            )
            ->assertJsonPath(
                'data.scope.organizational_assignment_id',
                $this->assignmentId,
            )
            ->assertJsonPath(
                'data.scope.organization_id',
                $this->organizationId,
            )
            ->assertJsonPath(
                'data.scope.organization_unit_id',
                null,
            )
            ->assertJsonPath(
                'data.is_global_superadmin',
                false,
            )
            ->assertJsonPath(
                'data.permissions.0',
                'academic.grades.write',
            )
            ->assertJsonPath(
                'data.permissions.1',
                'dormitory.rooms.manage',
            )
            ->assertJsonCount(
                2,
                'data.permissions',
            );

        $this->assertNotContains(
            'dormitory.rooms.view',
            $response->json(
                'data.permissions',
            ),
        );
    }

    public function test_workspace_capability_endpoint_requires_organizational_context(): void
    {
        $this
            ->withToken(
                $this->issueToken(),
            )
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            )
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'ORGANIZATIONAL_CONTEXT_REQUIRED',
            );
    }

    public function test_tenant_capability_endpoint_requires_verified_tenant_context(): void
    {
        $this
            ->getJson(
                '/api/v1/core/authorization/capabilities',
            )
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_CONTEXT_DENIED',
            );
    }

    private function createFixture(): void
    {
        DB::table('tenants')->insert([
            'id' =>
            $this->tenantId,
            'name' =>
            'Capability Projection Tenant',
            'subdomain' =>
            sprintf(
                'capability-%s',
                Str::lower(
                    Str::random(8),
                ),
            ),
            'is_active' =>
            true,
            'created_at' =>
            now(),
            'updated_at' =>
            now(),
        ]);

        DB::table('persons')->insert([
            'id' =>
            $this->personId,
            'name' =>
            'Capability Projection User',
            'status' =>
            'ACTIVE',
            'created_at' =>
            now(),
            'updated_at' =>
            now(),
        ]);

        DB::table('users')->insert([
            'id' =>
            $this->userId,
            'person_id' =>
            $this->personId,
            'email' =>
            sprintf(
                'capability-%s@educore.test',
                Str::lower(
                    Str::random(10),
                ),
            ),
            'password' =>
            bcrypt('secret123'),
            'status' =>
            'ACTIVE',
            'is_superadmin' =>
            false,
            'created_at' =>
            now(),
            'updated_at' =>
            now(),
        ]);

        DB::table('memberships')->insert([
            'id' =>
            $this->membershipId,
            'person_id' =>
            $this->personId,
            'tenant_id' =>
            $this->tenantId,
            'status' =>
            'ACTIVE',
            'created_at' =>
            now(),
            'updated_at' =>
            now(),
        ]);

        DB::table('organizations')->insert([
            'id' =>
            $this->organizationId,
            'tenant_id' =>
            $this->tenantId,
            'name' =>
            'Capability Organization',
            'code' =>
            'CAPABILITY-ORG',
            'is_active' =>
            true,
            'created_at' =>
            now(),
            'updated_at' =>
            now(),
        ]);

        DB::table(
            'organizational_assignments',
        )->insert([
            'id' =>
            $this->assignmentId,
            'tenant_id' =>
            $this->tenantId,
            'membership_id' =>
            $this->membershipId,
            'organization_id' =>
            $this->organizationId,
            'organization_unit_id' =>
            null,
            'status' =>
            'ACTIVE',
            'created_at' =>
            now(),
            'updated_at' =>
            now(),
        ]);

        DB::table('roles')->insert([
            [
                'id' =>
                $this->tenantRoleId,
                'name' =>
                'capability-tenant-role',
                'display_name' =>
                'Capability Tenant Role',
                'description' =>
                'Tenant role used by capability API test.',
                'created_at' =>
                now(),
                'updated_at' =>
                now(),
            ],
            [
                'id' =>
                $this->workspaceRoleId,
                'name' =>
                'capability-workspace-role',
                'display_name' =>
                'Capability Workspace Role',
                'description' =>
                'Workspace role used by capability API test.',
                'created_at' =>
                now(),
                'updated_at' =>
                now(),
            ],
        ]);

        DB::table('permissions')->insert([
            [
                'id' =>
                $this->tenantPermissionId,
                'name' =>
                'academic.grades.write',
                'display_name' =>
                'Write Academic Grades',
                'description' =>
                null,
                'module' =>
                'Academic',
                'created_at' =>
                now(),
                'updated_at' =>
                now(),
            ],
            [
                'id' =>
                $this->workspacePermissionId,
                'name' =>
                'dormitory.rooms.manage',
                'display_name' =>
                'Manage Dormitory Rooms',
                'description' =>
                null,
                'module' =>
                'Dormitory',
                'created_at' =>
                now(),
                'updated_at' =>
                now(),
            ],
            [
                'id' =>
                $this->deniedPermissionId,
                'name' =>
                'dormitory.rooms.view',
                'display_name' =>
                'View Dormitory Rooms',
                'description' =>
                null,
                'module' =>
                'Dormitory',
                'created_at' =>
                now(),
                'updated_at' =>
                now(),
            ],
        ]);

        DB::table('role_permissions')->insert([
            [
                'role_id' =>
                $this->tenantRoleId,
                'permission_id' =>
                $this->tenantPermissionId,
            ],
            [
                'role_id' =>
                $this->workspaceRoleId,
                'permission_id' =>
                $this->workspacePermissionId,
            ],
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' =>
            $this->membershipId,
            'role_id' =>
            $this->tenantRoleId,
        ]);

        DB::table(
            'organizational_assignment_roles',
        )->insert([
            'organizational_assignment_id' =>
            $this->assignmentId,
            'role_id' =>
            $this->workspaceRoleId,
        ]);
    }

    private function issueToken(): string
    {
        return app(
            TokenManagerInterface::class,
        )->issueToken(
            $this->userId,
            $this->tenantId,
            [
                'membership_id' =>
                $this->membershipId,
            ],
        );
    }
}
