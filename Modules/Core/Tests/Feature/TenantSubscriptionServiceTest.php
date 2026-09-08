<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Models\TenantAddon;
use Modules\Core\Subscription\Models\TenantSubscription;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class TenantSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantSubscriptionService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(TenantSubscriptionService::class);

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Subscription Service Uji',
            'subdomain' => 'tenant-subscription-service-uji',
            'is_active' => true,
        ]);

        $this->tenantId = $tenant->id;
    }

    public function test_assign_plan_creates_trial_subscription(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'assign-plan', 'name' => 'Assign Plan']);

        $subscription = $this->service->assignPlan($this->tenantId, $plan->id);

        $this->assertSame(TenantSubscription::STATUS_TRIAL, $subscription->status);
        $this->assertNull($subscription->activated_at);
        $this->assertSame($plan->id, $subscription->plan_id);
    }

    public function test_assign_plan_reuses_the_same_row_when_switching_plans(): void
    {
        $planA = SubscriptionPlan::query()->create(['code' => 'plan-a-switch', 'name' => 'Plan A']);
        $planB = SubscriptionPlan::query()->create(['code' => 'plan-b-switch', 'name' => 'Plan B']);

        $first = $this->service->assignPlan($this->tenantId, $planA->id);
        $second = $this->service->assignPlan($this->tenantId, $planB->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($planB->id, $second->plan_id);
        $this->assertSame(1, TenantSubscription::query()->where('tenant_id', $this->tenantId)->count());
    }

    public function test_activate_plan_transitions_trial_to_active(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'activate-plan', 'name' => 'Activate Plan']);
        $this->service->assignPlan($this->tenantId, $plan->id);

        $subscription = $this->service->activatePlan($this->tenantId);

        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->activated_at);
    }

    public function test_assign_addon_creates_trial_addon(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'assign-addon-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'assign-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $tenantAddon = $this->service->assignAddon($this->tenantId, $addon->id);

        $this->assertSame(TenantAddon::STATUS_TRIAL, $tenantAddon->status);
        $this->assertNull($tenantAddon->locked_at);
    }

    public function test_revoke_addon_locks_readonly_using_current_plan_grace_period(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'grace-plan', 'name' => 'Grace Plan', 'grace_period_days' => 45]);
        $this->service->assignPlan($this->tenantId, $plan->id);

        $feature = SubscriptionFeature::query()->create(['code' => 'revoke-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'revoke-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);
        $this->service->assignAddon($this->tenantId, $addon->id);

        $tenantAddon = $this->service->revokeAddon($this->tenantId, $addon->id);

        $this->assertSame(TenantAddon::STATUS_LOCKED_READONLY, $tenantAddon->status);
        $this->assertNotNull($tenantAddon->locked_at);
        $this->assertEqualsWithDelta(
            $tenantAddon->locked_at->addDays(45)->timestamp,
            $tenantAddon->readonly_until->timestamp,
            2,
        );
    }

    public function test_reassigning_a_previously_locked_addon_resets_lock_fields(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'reassign-plan', 'name' => 'Reassign Plan']);
        $this->service->assignPlan($this->tenantId, $plan->id);

        $feature = SubscriptionFeature::query()->create(['code' => 'reassign-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'reassign-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $this->service->assignAddon($this->tenantId, $addon->id);
        $locked = $this->service->revokeAddon($this->tenantId, $addon->id);

        $reassigned = $this->service->assignAddon($this->tenantId, $addon->id);

        $this->assertSame($locked->id, $reassigned->id);
        $this->assertSame(TenantAddon::STATUS_TRIAL, $reassigned->status);
        $this->assertNull($reassigned->locked_at);
        $this->assertNull($reassigned->readonly_until);
        $this->assertSame(1, TenantAddon::query()->where('tenant_id', $this->tenantId)->count());
    }

    public function test_sync_expired_locks_moves_readonly_to_hidden_after_grace_period(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'expire-plan', 'name' => 'Expire Plan', 'grace_period_days' => 1]);
        $this->service->assignPlan($this->tenantId, $plan->id);

        $feature = SubscriptionFeature::query()->create(['code' => 'expire-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'expire-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);
        $this->service->assignAddon($this->tenantId, $addon->id);
        $this->service->revokeAddon($this->tenantId, $addon->id);

        // Majukan waktu melewati masa tenggang.
        Carbon::setTestNow(now()->addDays(2));

        $this->service->syncExpiredLocks($this->tenantId);

        $tenantAddon = TenantAddon::query()
            ->where('tenant_id', $this->tenantId)
            ->where('addon_id', $addon->id)
            ->firstOrFail();

        $this->assertSame(TenantAddon::STATUS_LOCKED_HIDDEN, $tenantAddon->status);

        Carbon::setTestNow();
    }

    public function test_effective_feature_codes_combines_plan_and_active_addons(): void
    {
        $planFeature = SubscriptionFeature::query()->create(['code' => 'plan-feature', 'name' => 'Plan Feature']);
        $addonFeature = SubscriptionFeature::query()->create(['code' => 'addon-feature', 'name' => 'Addon Feature']);

        $plan = SubscriptionPlan::query()->create(['code' => 'effective-plan', 'name' => 'Effective Plan']);
        $plan->features()->attach($planFeature->id);

        $addon = Addon::query()->create(['code' => 'effective-addon', 'name' => 'Addon', 'feature_id' => $addonFeature->id]);

        $this->service->assignPlan($this->tenantId, $plan->id);
        $this->service->assignAddon($this->tenantId, $addon->id);

        $codes = $this->service->effectiveFeatureCodes($this->tenantId);

        $this->assertContains('plan-feature', $codes);
        $this->assertContains('addon-feature', $codes);
    }

    public function test_effective_feature_codes_excludes_locked_addon_features(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'exclude-plan', 'name' => 'Exclude Plan']);
        $this->service->assignPlan($this->tenantId, $plan->id);

        $feature = SubscriptionFeature::query()->create(['code' => 'locked-out-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'locked-out-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);
        $this->service->assignAddon($this->tenantId, $addon->id);
        $this->service->revokeAddon($this->tenantId, $addon->id);

        $codes = $this->service->effectiveFeatureCodes($this->tenantId);

        $this->assertNotContains('locked-out-feature', $codes);
    }

    public function test_effective_feature_codes_changes_immediately_when_plan_is_downgraded(): void
    {
        $feature = SubscriptionFeature::query()->create(['code' => 'downgrade-feature', 'name' => 'Feature']);

        $bigPlan = SubscriptionPlan::query()->create(['code' => 'downgrade-big', 'name' => 'Big']);
        $bigPlan->features()->attach($feature->id);

        $smallPlan = SubscriptionPlan::query()->create(['code' => 'downgrade-small', 'name' => 'Small']);

        $this->service->assignPlan($this->tenantId, $bigPlan->id);
        $this->assertContains('downgrade-feature', $this->service->effectiveFeatureCodes($this->tenantId));

        $this->service->assignPlan($this->tenantId, $smallPlan->id);

        $this->assertNotContains('downgrade-feature', $this->service->effectiveFeatureCodes($this->tenantId));
    }
}
