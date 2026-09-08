<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Models\TenantAddon;
use Modules\Core\Subscription\Models\TenantSubscription;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class SubscriptionCatalogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_can_be_created_with_default_grace_period(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'code' => 'test-plan',
            'name' => 'Test Plan',
        ]);

        $this->assertSame(30, $plan->grace_period_days);
        $this->assertTrue($plan->is_active);
    }

    public function test_plan_code_must_be_unique(): void
    {
        SubscriptionPlan::query()->create(['code' => 'dup-plan', 'name' => 'Dup 1']);

        $this->expectException(QueryException::class);

        SubscriptionPlan::query()->create(['code' => 'dup-plan', 'name' => 'Dup 2']);
    }

    public function test_feature_code_must_be_unique(): void
    {
        SubscriptionFeature::query()->create(['code' => 'dup-feature', 'name' => 'Dup 1']);

        $this->expectException(QueryException::class);

        SubscriptionFeature::query()->create(['code' => 'dup-feature', 'name' => 'Dup 2']);
    }

    public function test_plan_can_have_multiple_features_via_pivot(): void
    {
        $plan = SubscriptionPlan::query()->create(['code' => 'pivot-plan', 'name' => 'Pivot Plan']);
        $featureA = SubscriptionFeature::query()->create(['code' => 'feature-a', 'name' => 'Feature A']);
        $featureB = SubscriptionFeature::query()->create(['code' => 'feature-b', 'name' => 'Feature B']);

        $plan->features()->attach([$featureA->id, $featureB->id]);

        $this->assertCount(2, $plan->fresh()->features);
    }

    public function test_addon_requires_existing_feature(): void
    {
        $this->expectException(QueryException::class);

        Addon::query()->create([
            'code' => 'orphan-addon',
            'name' => 'Orphan Addon',
            'feature_id' => '01a00000-0000-7000-8000-000000000000',
        ]);
    }

    public function test_tenant_subscription_allows_only_one_row_per_tenant(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Subscription Uji',
            'subdomain' => 'tenant-subscription-uji',
            'is_active' => true,
        ]);

        $plan = SubscriptionPlan::query()->create(['code' => 'single-row-plan', 'name' => 'Single Row Plan']);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ]);

        $this->expectException(QueryException::class);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ]);
    }

    public function test_tenant_subscription_check_constraint_rejects_unknown_status(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Status Uji',
            'subdomain' => 'tenant-status-uji',
            'is_active' => true,
        ]);

        $plan = SubscriptionPlan::query()->create(['code' => 'status-check-plan', 'name' => 'Status Check Plan']);

        $this->expectException(QueryException::class);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'unknown-status',
        ]);
    }

    public function test_tenant_addon_check_constraint_rejects_unknown_status(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Addon Status Uji',
            'subdomain' => 'tenant-addon-status-uji',
            'is_active' => true,
        ]);

        $feature = SubscriptionFeature::query()->create(['code' => 'addon-status-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create([
            'code' => 'addon-status-addon',
            'name' => 'Addon',
            'feature_id' => $feature->id,
        ]);

        $this->expectException(QueryException::class);

        TenantAddon::query()->create([
            'tenant_id' => $tenant->id,
            'addon_id' => $addon->id,
            'status' => 'unknown-status',
        ]);
    }

    public function test_tenant_addon_allows_only_one_row_per_tenant_and_addon(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Addon Unik Uji',
            'subdomain' => 'tenant-addon-unik-uji',
            'is_active' => true,
        ]);

        $feature = SubscriptionFeature::query()->create(['code' => 'unique-addon-feature', 'name' => 'Feature']);
        $addon = Addon::query()->create([
            'code' => 'unique-addon',
            'name' => 'Addon',
            'feature_id' => $feature->id,
        ]);

        TenantAddon::query()->create([
            'tenant_id' => $tenant->id,
            'addon_id' => $addon->id,
        ]);

        $this->expectException(QueryException::class);

        TenantAddon::query()->create([
            'tenant_id' => $tenant->id,
            'addon_id' => $addon->id,
        ]);
    }

    public function test_tenant_subscription_is_removed_when_tenant_is_hard_deleted(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Cascade Uji',
            'subdomain' => 'tenant-cascade-uji',
            'is_active' => true,
        ]);

        $plan = SubscriptionPlan::query()->create(['code' => 'cascade-plan', 'name' => 'Cascade Plan']);

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ]);

        $tenant->forceDelete();

        $this->assertDatabaseMissing('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
        ]);
    }
}
