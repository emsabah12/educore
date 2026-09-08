<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Identity\Models\User;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class PlatformPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_plans_with_feature_counts(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $plan = SubscriptionPlan::query()->create(['code' => 'starter', 'name' => 'Starter']);
        $feature = SubscriptionFeature::query()->create(['code' => 'feature-x', 'name' => 'Feature X']);
        $plan->features()->attach($feature->id);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.plans.index'))
            ->assertOk()
            ->assertSee('starter')
            ->assertSee('Starter');
    }

    public function test_store_creates_new_plan(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.plans.store'), [
                'code' => 'premium',
                'name' => 'Premium',
                'description' => 'Paket premium.',
                'grace_period_days' => 45,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('subscription_plans', [
            'code' => 'premium',
            'name' => 'Premium',
            'grace_period_days' => 45,
        ]);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        SubscriptionPlan::query()->create(['code' => 'dup', 'name' => 'Dup']);

        $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.plans.store'), [
                'code' => 'dup',
                'name' => 'Dup 2',
                'grace_period_days' => 30,
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_update_changes_attributes_and_syncs_features(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $plan = SubscriptionPlan::query()->create(['code' => 'update-plan', 'name' => 'Before', 'grace_period_days' => 30]);
        $feature = SubscriptionFeature::query()->create(['code' => 'update-feature', 'name' => 'Feature']);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->put(route('platform.plans.update', $plan->id), [
                'name' => 'After',
                'grace_period_days' => 60,
                'is_active' => '1',
                'feature_ids' => [$feature->id],
            ]);

        $response->assertRedirect(route('platform.plans.show', $plan->id));

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'name' => 'After',
            'grace_period_days' => 60,
        ]);

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
        ]);
    }

    public function test_update_does_not_accept_code_change(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $plan = SubscriptionPlan::query()->create(['code' => 'immutable-code', 'name' => 'Plan']);

        $this
            ->actingAs($superadmin, 'web')
            ->put(route('platform.plans.update', $plan->id), [
                'code' => 'changed-code',
                'name' => 'Plan',
                'grace_period_days' => 30,
            ]);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'code' => 'immutable-code',
        ]);
    }

    public function test_index_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create(['is_superadmin' => false]);

        $this->actingAs($regularUser, 'web')
            ->get(route('platform.plans.index'))
            ->assertForbidden();
    }

    public function test_show_returns_not_found_for_unknown_plan(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.plans.show', UuidV7::generate()))
            ->assertNotFound();
    }
}
