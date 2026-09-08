<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Identity\Models\User;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class PlatformAddonControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_addons_with_their_feature(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $feature = SubscriptionFeature::query()->create(['code' => 'index-feature', 'name' => 'Feature']);
        Addon::query()->create([
            'code' => 'index-addon',
            'name' => 'Index Addon',
            'feature_id' => $feature->id,
        ]);

        $this->actingAs($superadmin, 'web')
            ->get(route('platform.addons.index'))
            ->assertOk()
            ->assertSee('index-addon')
            ->assertSee('index-feature');
    }

    public function test_store_creates_new_addon(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);
        $feature = SubscriptionFeature::query()->create(['code' => 'store-feature', 'name' => 'Feature']);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.addons.store'), [
                'code' => 'store-addon',
                'name' => 'Store Addon',
                'feature_id' => $feature->id,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('addons', [
            'code' => 'store-addon',
            'feature_id' => $feature->id,
        ]);
    }

    public function test_store_rejects_unknown_feature(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $this
            ->actingAs($superadmin, 'web')
            ->post(route('platform.addons.store'), [
                'code' => 'orphan-addon',
                'name' => 'Orphan',
                'feature_id' => UuidV7::generate(),
            ])
            ->assertSessionHasErrors(['feature_id']);
    }

    public function test_update_changes_addon_attributes(): void
    {
        $superadmin = User::factory()->create(['is_superadmin' => true]);

        $featureA = SubscriptionFeature::query()->create(['code' => 'feature-a-addon', 'name' => 'A']);
        $featureB = SubscriptionFeature::query()->create(['code' => 'feature-b-addon', 'name' => 'B']);

        $addon = Addon::query()->create([
            'code' => 'update-addon',
            'name' => 'Before',
            'feature_id' => $featureA->id,
        ]);

        $response = $this
            ->actingAs($superadmin, 'web')
            ->put(route('platform.addons.update', $addon->id), [
                'name' => 'After',
                'feature_id' => $featureB->id,
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('platform.addons.show', $addon->id));

        $this->assertDatabaseHas('addons', [
            'id' => $addon->id,
            'name' => 'After',
            'feature_id' => $featureB->id,
        ]);
    }

    public function test_index_is_forbidden_for_non_superadmin(): void
    {
        $regularUser = User::factory()->create(['is_superadmin' => false]);

        $this->actingAs($regularUser, 'web')
            ->get(route('platform.addons.index'))
            ->assertForbidden();
    }
}
