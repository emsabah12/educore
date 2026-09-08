<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Identity\Models\User;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Models\TenantAddon;
use Modules\Core\Subscription\Models\TenantSubscription;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class PlatformTenantSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::query()->create([
            'name' => 'Tenant Langganan Uji',
            'subdomain' => 'tenant-langganan-uji',
            'is_active' => true,
        ]);

        $this->tenantId = $tenant->id;
    }

    public function test_assign_plan_sets_trial_subscription(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);
        $plan = SubscriptionPlan::query()->create(['code' => 'ctrl-assign-plan', 'name' => 'Ctrl Plan']);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.tenants.subscription.assign-plan', $this->tenantId), [
                'plan_id' => $plan->id,
            ]);

        $response->assertRedirect(route('platform.tenants.show', $this->tenantId));

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $this->tenantId,
            'plan_id' => $plan->id,
            'status' => TenantSubscription::STATUS_TRIAL,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenantId,
            'event_type' => 'tenant.plan_assigned',
        ]);
    }

    public function test_activate_plan_transitions_to_active(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);
        $plan = SubscriptionPlan::query()->create(['code' => 'ctrl-activate-plan', 'name' => 'Ctrl Plan']);

        $this->app->make(TenantSubscriptionService::class)->assignPlan($this->tenantId, $plan->id);

        $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.tenants.subscription.activate-plan', $this->tenantId))
            ->assertRedirect(route('platform.tenants.show', $this->tenantId));

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $this->tenantId,
            'status' => TenantSubscription::STATUS_ACTIVE,
        ]);
    }

    public function test_assign_addon_creates_trial_tenant_addon(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);
        $feature = SubscriptionFeature::query()->create(['code' => 'ctrl-assign-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'ctrl-assign-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.tenants.addons.assign', $this->tenantId), [
                'addon_id' => $addon->id,
            ]);

        $response->assertRedirect(route('platform.tenants.show', $this->tenantId));

        $this->assertDatabaseHas('tenant_addons', [
            'tenant_id' => $this->tenantId,
            'addon_id' => $addon->id,
            'status' => TenantAddon::STATUS_TRIAL,
        ]);
    }

    public function test_revoke_addon_locks_readonly(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $plan = SubscriptionPlan::query()->create(['code' => 'ctrl-revoke-plan', 'name' => 'Ctrl Plan']);
        $feature = SubscriptionFeature::query()->create(['code' => 'ctrl-revoke-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'ctrl-revoke-addon', 'name' => 'Addon', 'feature_id' => $feature->id]);

        $service = $this->app->make(TenantSubscriptionService::class);
        $service->assignPlan($this->tenantId, $plan->id);
        $service->assignAddon($this->tenantId, $addon->id);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.tenants.addons.revoke', [$this->tenantId, $addon->id]));

        $response->assertRedirect(route('platform.tenants.show', $this->tenantId));

        $this->assertDatabaseHas('tenant_addons', [
            'tenant_id' => $this->tenantId,
            'addon_id' => $addon->id,
            'status' => TenantAddon::STATUS_LOCKED_READONLY,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenantId,
            'event_type' => 'tenant.addon_revoked',
        ]);
    }

    public function test_tenant_detail_page_displays_subscription_and_addons(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $plan = SubscriptionPlan::query()->create(['code' => 'ctrl-show-plan', 'name' => 'Ctrl Show Plan']);
        $feature = SubscriptionFeature::query()->create(['code' => 'ctrl-show-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create(['code' => 'ctrl-show-addon', 'name' => 'Ctrl Show Addon', 'feature_id' => $feature->id]);

        $service = $this->app->make(TenantSubscriptionService::class);
        $service->assignPlan($this->tenantId, $plan->id);
        $service->assignAddon($this->tenantId, $addon->id);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.tenants.show', $this->tenantId))
            ->assertOk()
            ->assertSee('Ctrl Show Plan')
            ->assertSee('Ctrl Show Addon');
    }

    public function test_assign_plan_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create(['is_superadmin' => false]);
        $plan = SubscriptionPlan::query()->create(['code' => 'ctrl-forbidden-plan', 'name' => 'Plan']);

        $this
            ->actingAs($regularUser, 'web')
            ->post(route('platform.tenants.subscription.assign-plan', $this->tenantId), [
                'plan_id' => $plan->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tenant_subscriptions', [
            'tenant_id' => $this->tenantId,
        ]);
    }
}
