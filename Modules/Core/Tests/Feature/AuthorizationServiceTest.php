<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Tests\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $userAId;

    private string $userBId;

    private string $tenantAId;

    private string $tenantBId;

    private string $membershipAId;

    private string $membershipBId;

    private string $adminRoleId;

    private string $permissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userAId = (string) Str::uuid7();
        $this->userBId = (string) Str::uuid7();

        $this->tenantAId = (string) Str::uuid7();
        $this->tenantBId = (string) Str::uuid7();

        $this->membershipAId = (string) Str::uuid7();
        $this->membershipBId = (string) Str::uuid7();

        $this->adminRoleId = (string) Str::uuid7();
        $this->permissionId = (string) Str::uuid7();

        DB::table('users')->insert([
            [
                'id' => $this->userAId,
                'name' => 'Authorization User A',
                'email' => 'authorization-a@example.test',
                'password' => bcrypt('password'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->userBId,
                'name' => 'Authorization User B',
                'email' => 'authorization-b@example.test',
                'password' => bcrypt('password'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'name' => 'Authorization Tenant A',
                'subdomain' => 'authorization-a',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->tenantBId,
                'name' => 'Authorization Tenant B',
                'subdomain' => 'authorization-b',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('roles')->insert([
            'id' => $this->adminRoleId,
            'name' => 'admin',
            'display_name' => 'Administrator',
            'description' => 'Administrator role for contextual authorization testing.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'id' => $this->permissionId,
            'name' => 'notification.dispatch',
            'display_name' => 'Dispatch Notification',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            [
                'id' => $this->membershipAId,
                'user_id' => $this->userAId,
                'tenant_id' => $this->tenantAId,
                'role' => 'staff',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->membershipBId,
                'user_id' => $this->userBId,
                'tenant_id' => $this->tenantBId,
                'role' => 'staff',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('membership_roles')->insert([
            [
                'membership_id' => $this->membershipAId,
                'role_id' => $this->adminRoleId,
            ],
            [
                'membership_id' => $this->membershipBId,
                'role_id' => $this->adminRoleId,
            ],
        ]);

        DB::table('role_permissions')->insert([
            'role_id' => $this->adminRoleId,
            'permission_id' => $this->permissionId,
        ]);
    }

    public function test_user_can_authorize_role_on_owned_active_membership(): void
    {
        $service = app(AuthorizationServiceInterface::class);

        $this->assertTrue(
            $service->hasRoleInMembership(
                $this->userAId,
                $this->membershipAId,
                'admin',
                $this->tenantAId
            )
        );
    }

    public function test_user_cannot_authorize_role_on_another_users_membership(): void
    {
        $service = app(AuthorizationServiceInterface::class);

        $this->assertFalse(
            $service->hasRoleInMembership(
                $this->userAId,
                $this->membershipBId,
                'admin',
                $this->tenantBId
            )
        );
    }

    public function test_user_cannot_authorize_role_from_wrong_tenant_context(): void
    {
        $service = app(AuthorizationServiceInterface::class);

        $this->assertFalse(
            $service->hasRoleInMembership(
                $this->userAId,
                $this->membershipAId,
                'admin',
                $this->tenantBId
            )
        );
    }

    public function test_inactive_membership_cannot_authorize_role(): void
    {
        DB::table('memberships')
            ->where('id', $this->membershipAId)
            ->update([
                'status' => 'INACTIVE',
            ]);

        $service = app(AuthorizationServiceInterface::class);

        $this->assertFalse(
            $service->hasRoleInMembership(
                $this->userAId,
                $this->membershipAId,
                'admin',
                $this->tenantAId
            )
        );
    }

    public function test_user_can_authorize_permission_through_membership_role(): void
    {
        $service = app(AuthorizationServiceInterface::class);

        $this->assertTrue(
            $service->hasPermissionInMembership(
                $this->userAId,
                $this->membershipAId,
                'notification.dispatch',
                $this->tenantAId
            )
        );
    }

    public function test_user_cannot_authorize_permission_on_another_users_membership(): void
    {
        $service = app(AuthorizationServiceInterface::class);

        $this->assertFalse(
            $service->hasPermissionInMembership(
                $this->userAId,
                $this->membershipBId,
                'notification.dispatch',
                $this->tenantBId
            )
        );
    }
}
