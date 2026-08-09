<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    private string $userAId;
    private string $userBId;

    private string $membershipAId;
    private string $membershipBId;

    private string $adminRoleId;
    private string $notificationPermissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = Str::uuid()->toString();
        $this->tenantBId = Str::uuid()->toString();

        $this->userAId = Str::uuid()->toString();
        $this->userBId = Str::uuid()->toString();

        $this->membershipAId = Str::uuid()->toString();
        $this->membershipBId = Str::uuid()->toString();

        $this->adminRoleId = Str::uuid()->toString();
        $this->notificationPermissionId = Str::uuid()->toString();

        $this->createTenants();
        $this->createUsers();
        $this->createMemberships();
        $this->createRoleAndPermission();
        $this->attachRoleAndPermission();
    }

    protected function tearDown(): void
    {
        $this->app->make(Request::class)
            ->attributes
            ->remove(
                'authenticated_membership_id',
            );

        app(TenantContextInterface::class)->clear();

        auth()->guard()->forgetUser();

        parent::tearDown();
    }

    public function test_user_can_authorize_role_on_owned_active_membership(): void
    {
        $this->authenticateAsUser(
            $this->userAId,
        );

        $this->setTenantContext(
            $this->tenantAId,
        );

        $this->setAuthenticatedMembership(
            $this->membershipAId,
        );

        $service = app(
            AuthorizationServiceInterface::class,
        );

        $this->assertTrue(
            $service->hasRole('admin'),
        );
    }

    public function test_user_cannot_authorize_role_on_another_users_membership(): void
    {
        $this->authenticateAsUser(
            $this->userAId,
        );

        $this->setTenantContext(
            $this->tenantBId,
        );

        $this->setAuthenticatedMembership(
            $this->membershipBId,
        );

        $service = app(
            AuthorizationServiceInterface::class,
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $service->hasRole('admin');
    }

    public function test_user_cannot_authorize_role_from_wrong_tenant_context(): void
    {
        $this->authenticateAsUser(
            $this->userAId,
        );

        $this->setTenantContext(
            $this->tenantBId,
        );

        $this->setAuthenticatedMembership(
            $this->membershipAId,
        );

        $service = app(
            AuthorizationServiceInterface::class,
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $service->hasRole('admin');
    }

    public function test_inactive_membership_cannot_authorize_role(): void
    {
        DB::table('memberships')
            ->where(
                'id',
                $this->membershipAId,
            )
            ->update([
                'status' => 'SUSPENDED',
                'updated_at' => now(),
            ]);

        $this->authenticateAsUser(
            $this->userAId,
        );

        $this->setTenantContext(
            $this->tenantAId,
        );

        $this->setAuthenticatedMembership(
            $this->membershipAId,
        );

        $service = app(
            AuthorizationServiceInterface::class,
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $service->hasRole('admin');
    }

    public function test_user_can_authorize_permission_through_membership_role(): void
    {
        $this->authenticateAsUser(
            $this->userAId,
        );

        $this->setTenantContext(
            $this->tenantAId,
        );

        $this->setAuthenticatedMembership(
            $this->membershipAId,
        );

        $service = app(
            AuthorizationServiceInterface::class,
        );

        $this->assertTrue(
            $service->hasPermission(
                'notification.dispatch',
            ),
        );
    }

    public function test_user_cannot_authorize_permission_on_another_users_membership(): void
    {
        $this->authenticateAsUser(
            $this->userAId,
        );

        $this->setTenantContext(
            $this->tenantBId,
        );

        $this->setAuthenticatedMembership(
            $this->membershipBId,
        );

        $service = app(
            AuthorizationServiceInterface::class,
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $service->hasPermission(
            'notification.dispatch',
        );
    }


    private function setAuthenticatedMembership(
        string $membershipId,
    ): void {
        $this->app->make(Request::class)
            ->attributes
            ->set(
                'authenticated_membership_id',
                $membershipId,
            );
    }

    private function createTenants(): void
    {
        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'name' => 'Authorization Tenant A',
                'subdomain' => sprintf(
                    'authorization-a-%s',
                    Str::lower(Str::random(8)),
                ),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->tenantBId,
                'name' => 'Authorization Tenant B',
                'subdomain' => sprintf(
                    'authorization-b-%s',
                    Str::lower(Str::random(8)),
                ),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createUsers(): void
    {
        DB::table('users')->insert([
            [
                'id' => $this->userAId,
                'name' => 'Authorization User A',
                'email' => sprintf(
                    'authorization-user-a-%s@educore.test',
                    Str::lower(Str::random(8)),
                ),
                'password' => bcrypt('secret123'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->userBId,
                'name' => 'Authorization User B',
                'email' => sprintf(
                    'authorization-user-b-%s@educore.test',
                    Str::lower(Str::random(8)),
                ),
                'password' => bcrypt('secret123'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createMemberships(): void
    {
        DB::table('memberships')->insert([
            [
                'id' => $this->membershipAId,
                'user_id' => $this->userAId,
                'tenant_id' => $this->tenantAId,

                /*
                 * Field role dipertahankan hanya untuk kompatibilitas
                 * skema lama. Authorization tidak membaca field ini.
                 */
                'role' => 'employee',

                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->membershipBId,
                'user_id' => $this->userBId,
                'tenant_id' => $this->tenantBId,
                'role' => 'employee',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createRoleAndPermission(): void
    {
        DB::table('roles')->insert([
            'id' => $this->adminRoleId,
            'name' => 'admin',
            'display_name' => 'Administrator',
            'description' => 'Tenant administrator role.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'id' => $this->notificationPermissionId,
            'name' => 'notification.dispatch',
            'display_name' => 'Dispatch Notification',
            // 'description' => 'Dispatch tenant notification.',
            // 'module' => 'Core',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachRoleAndPermission(): void
    {
        DB::table('membership_roles')->insert([
            'membership_id' => $this->membershipAId,
            'role_id' => $this->adminRoleId,
        ]);

        DB::table('role_permissions')->insert([
            'role_id' => $this->adminRoleId,
            'permission_id' => $this->notificationPermissionId,
        ]);
    }

    private function authenticateAsUser(
        string $userId,
    ): void {
        $user = User::query()->findOrFail(
            $userId,
        );

        $this->actingAs($user);
    }

    private function setTenantContext(
        string $tenantId,
    ): void {
        $tenant = Tenant::query()->findOrFail(
            $tenantId,
        );

        app(TenantContextInterface::class)
            ->setCurrentTenant($tenant);
    }
}
