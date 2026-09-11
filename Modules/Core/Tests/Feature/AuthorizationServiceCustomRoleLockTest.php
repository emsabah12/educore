<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class AuthorizationServiceCustomRoleLockTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $personId;

    private string $userId;

    private string $membershipId;

    private string $customRoleId;

    private string $customPermissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = UuidV7::generate();
        $this->personId = UuidV7::generate();
        $this->userId = UuidV7::generate();
        $this->membershipId = UuidV7::generate();
        $this->customRoleId = UuidV7::generate();
        $this->customPermissionId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Custom Role Lock Tenant',
            'subdomain' => sprintf('custom-role-lock-%s', Str::lower(Str::random(8))),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Custom Role Lock Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'person_id' => $this->personId,
            'email' => sprintf('custom-role-lock-%s@educore.test', Str::lower(Str::random(8))),
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

        // Role KUSTOM (tenant_id terisi) — bukan role sistem.
        DB::table('roles')->insert([
            'id' => $this->customRoleId,
            'tenant_id' => $this->tenantId,
            'name' => 'wali-kelas',
            'display_name' => 'Wali Kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'id' => $this->customPermissionId,
            'name' => 'academic.class.manage',
            'display_name' => 'Kelola Kelas',
            'module' => 'Academic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' => $this->membershipId,
            'role_id' => $this->customRoleId,
        ]);

        DB::table('role_permissions')->insert([
            'role_id' => $this->customRoleId,
            'permission_id' => $this->customPermissionId,
        ]);
    }

    protected function tearDown(): void
    {
        $this->app->make(Request::class)->attributes->remove('authenticated_membership_id');
        app(TenantContextInterface::class)->clear();
        auth()->guard()->forgetUser();

        parent::tearDown();
    }

    public function test_custom_role_permission_is_granted_while_feature_is_effective(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'custom_roles', 'name' => 'Custom Role']);
        $plan = SubscriptionPlan::query()->create(['code' => 'lock-test-plan-active', 'name' => 'Plan']);
        $plan->features()->attach($feature->id);

        $this->app->make(TenantSubscriptionService::class)->assignPlan($this->tenantId, $plan->id);

        $this->authenticate();

        $service = app(AuthorizationServiceInterface::class);

        $this->assertTrue($service->hasPermission('academic.class.manage'));
    }

    public function test_custom_role_permission_is_denied_when_tenant_never_had_the_feature(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'lock-test-plan-none', 'name' => 'Plan']);
        $this->app->make(TenantSubscriptionService::class)->assignPlan($this->tenantId, $plan->id);

        $this->authenticate();

        $service = app(AuthorizationServiceInterface::class);

        $this->assertFalse($service->hasPermission('academic.class.manage'));
    }

    public function test_custom_role_permission_is_denied_after_addon_providing_feature_is_revoked(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'custom_roles', 'name' => 'Custom Role']);
        $plan = SubscriptionPlan::query()->create(['code' => 'lock-test-plan-addon', 'name' => 'Plan']);
        $addon = Addon::query()->create(['code' => 'lock-test-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $subscriptionService = $this->app->make(TenantSubscriptionService::class);
        $subscriptionService->assignPlan($this->tenantId, $plan->id);
        $subscriptionService->assignAddon($this->tenantId, $addon->id);

        $this->authenticate();
        $service = app(AuthorizationServiceInterface::class);

        // Efektif sebelum dicabut.
        $this->assertTrue($service->hasPermission('academic.class.manage'));

        $subscriptionService->revokeAddon($this->tenantId, $addon->id);

        // §PRD: seluruh role kustom LANGSUNG tidak memberi akses,
        // bukan cuma tercatat "locked" di database tanpa efek nyata.
        $this->assertFalse($service->hasPermission('academic.class.manage'));
    }

    private function authenticate(): void
    {
        $this->actingAs(User::query()->findOrFail($this->userId));

        app(TenantContextInterface::class)->setCurrentTenant(
            Tenant::query()->findOrFail($this->tenantId),
        );

        $this->app->make(Request::class)
            ->attributes
            ->set('authenticated_membership_id', $this->membershipId);
    }
}
