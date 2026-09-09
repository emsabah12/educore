<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Subscription\Exceptions\CustomRoleFeatureNotAvailableException;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Services\TenantRoleService;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class TenantRoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantRoleService $service;
    private TenantSubscriptionService $subscriptionService;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(TenantRoleService::class);
        $this->subscriptionService = $this->app->make(TenantSubscriptionService::class);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Role Service Uji',
            'subdomain' => 'tenant-role-service-uji',
            'is_active' => true,
        ]);

        $this->tenantId = $tenant->id;
    }

    public function test_create_custom_role_succeeds_when_feature_is_effective_via_plan(): void
    {
        $feature = SubscriptionFeature::query()->create([
            'code' => 'custom_roles',
            'name' => 'Custom Role',
        ]);

        $plan = SubscriptionPlan::query()->create(['code' => 'trs-plan-with-feature', 'name' => 'Plan']);
        $plan->features()->attach($feature->id);

        $this->subscriptionService->assignPlan($this->tenantId, $plan->id);

        $role = $this->service->createCustomRole(
            $this->tenantId,
            'wali-kelas',
            'Wali Kelas',
        );

        $this->assertSame($this->tenantId, $role->tenant_id);
        $this->assertSame('wali-kelas', $role->name);
    }

    public function test_create_custom_role_fails_when_feature_is_not_effective(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'trs-plan-without-feature', 'name' => 'Plan']);
        $this->subscriptionService->assignPlan($this->tenantId, $plan->id);

        $this->expectException(CustomRoleFeatureNotAvailableException::class);

        $this->service->createCustomRole($this->tenantId, 'wali-kelas', 'Wali Kelas');
    }

    public function test_is_role_effective_is_always_true_for_global_roles(): void
    {
        $globalRole = Role::query()->create(['name' => 'trs-global-role', 'display_name' => 'Global']);

        $this->assertTrue($this->service->isRoleEffective($globalRole));
    }

    public function test_is_role_effective_is_false_when_custom_role_owner_tenant_lacks_feature(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'trs-lacks-feature-plan', 'name' => 'Plan']);
        $this->subscriptionService->assignPlan($this->tenantId, $plan->id);

        $customRole = Role::query()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'trs-orphan-custom-role',
            'display_name' => 'Orphan',
        ]);

        $this->assertFalse($this->service->isRoleEffective($customRole));
    }

    public function test_is_role_effective_follows_addon_lock_lifecycle(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'custom_roles', 'name' => 'Custom Role']);
        $plan = SubscriptionPlan::query()->create(['code' => 'trs-addon-plan', 'name' => 'Plan']);
        $addon = Addon::query()->create(['code' => 'trs-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $this->subscriptionService->assignPlan($this->tenantId, $plan->id);
        $this->subscriptionService->assignAddon($this->tenantId, $addon->id);

        $customRole = $this->service->createCustomRole($this->tenantId, 'trs-addon-role', 'Addon Role');
        $this->assertTrue($this->service->isRoleEffective($customRole));

        $this->subscriptionService->revokeAddon($this->tenantId, $addon->id);

        $this->assertFalse($this->service->isRoleEffective($customRole));
    }

    public function test_visibility_state_is_active_for_global_role(): void
    {
        $globalRole = Role::query()->create(['name' => 'trs-visibility-global', 'display_name' => 'Global']);

        $this->assertSame(
            TenantRoleService::VISIBILITY_ACTIVE,
            $this->service->visibilityStateFor($globalRole),
        );
    }

    public function test_visibility_state_is_locked_readonly_during_addon_grace_period(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'custom_roles', 'name' => 'Custom Role']);
        $plan = SubscriptionPlan::query()->create(['code' => 'trs-visibility-plan', 'name' => 'Plan', 'grace_period_days' => 30]);
        $addon = Addon::query()->create(['code' => 'trs-visibility-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $this->subscriptionService->assignPlan($this->tenantId, $plan->id);
        $this->subscriptionService->assignAddon($this->tenantId, $addon->id);

        $customRole = $this->service->createCustomRole($this->tenantId, 'trs-visibility-role', 'Role');

        $this->subscriptionService->revokeAddon($this->tenantId, $addon->id);

        $this->assertSame(
            TenantRoleService::VISIBILITY_LOCKED_READONLY,
            $this->service->visibilityStateFor($customRole->fresh()),
        );
    }

    public function test_visibility_state_is_locked_hidden_after_grace_period_expires(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'custom_roles', 'name' => 'Custom Role']);
        $plan = SubscriptionPlan::query()->create(['code' => 'trs-visibility-hidden-plan', 'name' => 'Plan', 'grace_period_days' => 1]);
        $addon = Addon::query()->create(['code' => 'trs-visibility-hidden-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $this->subscriptionService->assignPlan($this->tenantId, $plan->id);
        $this->subscriptionService->assignAddon($this->tenantId, $addon->id);

        $customRole = $this->service->createCustomRole($this->tenantId, 'trs-visibility-hidden-role', 'Role');

        $this->subscriptionService->revokeAddon($this->tenantId, $addon->id);

        Carbon::setTestNow(now()->addDays(2));

        $this->assertSame(
            TenantRoleService::VISIBILITY_LOCKED_HIDDEN,
            $this->service->visibilityStateFor($customRole->fresh()),
        );

        Carbon::setTestNow();
    }

    public function test_visibility_state_is_locked_hidden_when_lost_via_plan_downgrade_without_addon(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'custom_roles', 'name' => 'Custom Role']);
        $planWithFeature = SubscriptionPlan::query()->create(['code' => 'trs-plan-downgrade-from', 'name' => 'Plan']);
        $planWithFeature->features()->attach($feature->id);

        $this->subscriptionService->assignPlan($this->tenantId, $planWithFeature->id);

        $customRole = $this->service->createCustomRole($this->tenantId, 'trs-downgrade-role', 'Role');

        $barePlan = SubscriptionPlan::query()->create(['code' => 'trs-plan-downgrade-to', 'name' => 'Bare Plan']);
        $this->subscriptionService->assignPlan($this->tenantId, $barePlan->id);

        $this->assertSame(
            TenantRoleService::VISIBILITY_LOCKED_HIDDEN,
            $this->service->visibilityStateFor($customRole->fresh()),
        );
    }
}
