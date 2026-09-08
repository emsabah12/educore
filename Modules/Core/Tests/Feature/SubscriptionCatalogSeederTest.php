<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Subscription\Database\Seeders\SubscriptionCatalogSeeder;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Tests\TestCase;

final class SubscriptionCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_three_plans_and_custom_roles_addon(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $this->assertDatabaseHas('subscription_plans', ['code' => 'basic']);
        $this->assertDatabaseHas('subscription_plans', ['code' => 'pro']);
        $this->assertDatabaseHas('subscription_plans', ['code' => 'enterprise']);
        $this->assertDatabaseHas('subscription_features', ['code' => 'custom_roles']);
        $this->assertDatabaseHas('addons', ['code' => 'custom-roles-addon']);
    }

    public function test_only_enterprise_plan_includes_custom_roles_as_baseline_feature(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $enterprise = SubscriptionPlan::query()->where('code', 'enterprise')->firstOrFail();
        $basic = SubscriptionPlan::query()->where('code', 'basic')->firstOrFail();
        $pro = SubscriptionPlan::query()->where('code', 'pro')->firstOrFail();

        $this->assertTrue(
            $enterprise->features()->where('code', 'custom_roles')->exists(),
        );

        $this->assertFalse(
            $basic->features()->where('code', 'custom_roles')->exists(),
        );

        $this->assertFalse(
            $pro->features()->where('code', 'custom_roles')->exists(),
        );
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $this->assertSame(3, SubscriptionPlan::query()->count());
        $this->assertSame(1, SubscriptionFeature::query()->count());
        $this->assertSame(1, Addon::query()->count());
    }

    public function test_seeder_preserves_plan_id_across_reseed(): void
    {
        $this->seed(SubscriptionCatalogSeeder::class);

        $originalId = SubscriptionPlan::query()->where('code', 'basic')->value('id');

        $this->seed(SubscriptionCatalogSeeder::class);

        $idAfterReseed = SubscriptionPlan::query()->where('code', 'basic')->value('id');

        $this->assertSame($originalId, $idAfterReseed);
    }
}
