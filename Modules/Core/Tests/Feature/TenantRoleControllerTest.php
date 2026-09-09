<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Services\TenantRoleService;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class TenantRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $personId;
    private string $userId;
    private string $membershipId;
    private string $email;
    private string $managePermissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = UuidV7::generate();
        $this->personId = UuidV7::generate();
        $this->userId = UuidV7::generate();
        $this->membershipId = UuidV7::generate();
        $this->email = sprintf('tenant-role-%s@educore.test', Str::lower(Str::random(8)));

        $this->createFixture();
        $this->activateCustomRolesFeature();
    }

    public function test_index_lists_only_this_tenants_custom_roles(): void
    {
        $service = app(TenantRoleService::class);
        $service->createCustomRole($this->tenantId, 'wali-kelas', 'Wali Kelas');

        $otherTenantId = $this->createOtherTenant();
        $service->createCustomRole($otherTenantId, 'kepala-ops-lain', 'Kepala Ops Lain');

        $response = $this
            ->withToken($this->issueToken())
            ->getJson('/api/v1/core/tenant-roles');

        $response->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertContains('wali-kelas', $names);
        $this->assertNotContains('kepala-ops-lain', $names);
    }

    public function test_store_creates_custom_role_with_active_visibility(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson('/api/v1/core/tenant-roles', [
                'name' => 'wali-kelas',
                'display_name' => 'Wali Kelas',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'wali-kelas')
            ->assertJsonPath('data.visibility_state', 'active');
    }

    public function test_store_is_rejected_when_custom_role_feature_is_not_available(): void
    {
        $barePlan = SubscriptionPlan::query()->create(['code' => 'bare-plan', 'name' => 'Bare Plan']);
        app(TenantSubscriptionService::class)->assignPlan($this->tenantId, $barePlan->id);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson('/api/v1/core/tenant-roles', [
                'name' => 'wali-kelas',
                'display_name' => 'Wali Kelas',
            ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('code', 'CUSTOM_ROLE_FEATURE_NOT_AVAILABLE');
    }

    public function test_show_returns_not_found_for_role_belonging_to_another_tenant(): void
    {
        $otherTenantId = $this->createOtherTenant();

        $service = app(TenantRoleService::class);
        $otherRole = $service->createCustomRole($otherTenantId, 'role-tenant-lain', 'Role Tenant Lain');

        $this
            ->withToken($this->issueToken())
            ->getJson('/api/v1/core/tenant-roles/' . $otherRole->id)
            ->assertNotFound();
    }

    public function test_update_syncs_permissions_when_role_is_active(): void
    {
        $service = app(TenantRoleService::class);
        $role = $service->createCustomRole($this->tenantId, 'wali-kelas', 'Wali Kelas');

        $permissionId = (string) UuidV7::generate();

        DB::table('permissions')->insert([
            'id' => $permissionId,
            'name' => 'academic.class.manage',
            'display_name' => 'Kelola Kelas',
            'module' => 'Academic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withToken($this->issueToken())
            ->putJson('/api/v1/core/tenant-roles/' . $role->id, [
                'permission_ids' => [$permissionId],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permissionId,
        ]);
    }

    public function test_update_is_rejected_when_role_is_no_longer_active(): void
    {
        $feature = SubscriptionFeature::query()->where('code', 'custom_roles')->firstOrFail();
        $addon = Addon::query()->create([
            'code' => 'tenant-role-addon-lock',
            'name' => 'Addon',
            'feature_id' => $feature->id,
        ]);

        $subscriptionService = app(TenantSubscriptionService::class);

        $service = app(TenantRoleService::class);
        $role = $service->createCustomRole($this->tenantId, 'wali-kelas', 'Wali Kelas');

        $barePlan = SubscriptionPlan::query()->create(['code' => 'bare-plan-lock', 'name' => 'Bare Plan']);
        $subscriptionService->assignPlan($this->tenantId, $barePlan->id);
        $subscriptionService->assignAddon($this->tenantId, $addon->id);
        $subscriptionService->revokeAddon($this->tenantId, $addon->id);

        $this
            ->withToken($this->issueToken())
            ->putJson('/api/v1/core/tenant-roles/' . $role->id, [
                'permission_ids' => [],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CUSTOM_ROLE_NOT_EDITABLE');
    }

    public function test_assignable_permissions_returns_catalog(): void
    {
        DB::table('permissions')->insert([
            'id' => UuidV7::generate(),
            'name' => 'academic.class.manage',
            'display_name' => 'Kelola Kelas',
            'module' => 'Academic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson('/api/v1/core/tenant-roles/assignable-permissions');

        $response->assertOk();

        $this->assertContains(
            'academic.class.manage',
            array_column($response->json('data'), 'name'),
        );
    }

    public function test_index_is_forbidden_without_manage_permission(): void
    {
        $otherPersonId = UuidV7::generate();
        $otherUserId = UuidV7::generate();
        $otherMembershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $otherPersonId,
            'name' => 'No Permission Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $otherUserId,
            'person_id' => $otherPersonId,
            'email' => sprintf('no-perm-%s@educore.test', Str::lower(Str::random(8))),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $otherMembershipId,
            'person_id' => $otherPersonId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = app(TokenManagerInterface::class)->issueToken(
            $otherUserId,
            $this->tenantId,
            ['membership_id' => $otherMembershipId],
        );

        $this
            ->withToken($token)
            ->getJson('/api/v1/core/tenant-roles')
            ->assertForbidden();
    }

    public function test_index_accepts_browser_session_without_exposing_bearer(): void
    {
        config(['session.driver' => 'array']);

        $bearerCredential = $this->loginBrowserSessionAndAttachCookie();

        $response = $this
            ->withHeader(InjectBrowserTenantContext::HEADER, $this->membershipId)
            ->getJson('/api/v1/core/tenant-roles');

        $response->assertOk();

        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    private function createFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Tenant Role Controller Uji',
            'subdomain' => sprintf('tenant-role-ctrl-%s', Str::lower(Str::random(8))),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Tenant Role Controller Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'person_id' => $this->personId,
            'email' => $this->email,
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->membershipId,
            'person_id' => $this->personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = UuidV7::generate();

        DB::table('roles')->insert([
            'id' => $roleId,
            'tenant_id' => null,
            'name' => sprintf('tenant-role-admin-%s', Str::lower(Str::random(6))),
            'display_name' => 'Admin Uji',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->managePermissionId = (string) UuidV7::generate();

        DB::table('permissions')->insert([
            'id' => $this->managePermissionId,
            'name' => 'tenant.custom-roles.manage',
            'display_name' => 'Kelola Role Kustom Tenant',
            'module' => 'Core',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $this->managePermissionId,
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' => $this->membershipId,
            'role_id' => $roleId,
        ]);
    }

    private function activateCustomRolesFeature(): void
    {
        $feature = SubscriptionFeature::query()->updateOrCreate(
            ['code' => 'custom_roles'],
            ['name' => 'Custom Role'],
        );

        $plan = SubscriptionPlan::query()->create([
            'code' => sprintf('tenant-role-plan-%s', Str::lower(Str::random(6))),
            'name' => 'Plan With Custom Roles',
        ]);

        $plan->features()->attach($feature->id);

        app(TenantSubscriptionService::class)->assignPlan($this->tenantId, $plan->id);
    }

    private function createOtherTenant(): string
    {
        $otherTenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $otherTenantId,
            'name' => 'Other Tenant Role Ctrl',
            'subdomain' => sprintf('other-tenant-role-ctrl-%s', Str::lower(Str::random(8))),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = SubscriptionFeature::query()->where('code', 'custom_roles')->firstOrFail();

        $plan = SubscriptionPlan::query()->create([
            'code' => sprintf('other-tenant-plan-%s', Str::lower(Str::random(6))),
            'name' => 'Other Tenant Plan',
        ]);

        $plan->features()->attach($feature->id);

        app(TenantSubscriptionService::class)->assignPlan($otherTenantId, $plan->id);

        return $otherTenantId;
    }

    private function issueToken(): string
    {
        return app(TokenManagerInterface::class)->issueToken(
            $this->userId,
            $this->tenantId,
            ['membership_id' => $this->membershipId],
        );
    }

    private function loginBrowserSessionAndAttachCookie(): string
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'identifier' => $this->email,
            'password' => 'secret123',
        ])->assertOk();

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app['session']->getId(),
            );

        $this->postJson(
            sprintf('/api/v1/browser/user/memberships/%s/switch', $this->membershipId),
        )->assertOk();

        $browserAuthState = $this->app['session']->get('educore.browser_auth');

        $this->assertIsArray($browserAuthState);

        $bearerCredential = $browserAuthState['membership_credentials'][$this->membershipId] ?? null;

        $this->assertIsString($bearerCredential);

        return $bearerCredential;
    }

    private function sessionCookieName(): string
    {
        $cookieName = config('session.cookie');

        $this->assertIsString($cookieName);

        return $cookieName;
    }
}
