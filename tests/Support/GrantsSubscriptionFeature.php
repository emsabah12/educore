<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Services\TenantSubscriptionService;

/**
 * Shared test helper untuk memberi tenant sebuah Subscription feature
 * lewat plan assignment (mis. `hr_module`), supaya endpoint yang
 * dilindungi middleware `tenant.feature:<code>` (CheckTenantFeature)
 * bisa diuji tanpa mengulang setup plan/feature di setiap test file.
 *
 * Sengaja HANYA `assignPlan()` (tanpa `activatePlan()`) — lihat
 * TenantSubscriptionService::effectiveFeatureCodes(): fitur BAWAAN
 * plan tidak bergantung status TRIAL/ACTIVE subscription-nya, jadi
 * assign saja sudah cukup untuk membuat fitur "effective".
 */
trait GrantsSubscriptionFeature
{
    private function grantTenantFeature(
        string $tenantId,
        string $featureCode,
    ): void {
        $plan = SubscriptionPlan::query()
            ->whereHas(
                'features',
                fn ($query) => $query->where(
                    'code',
                    $featureCode,
                ),
            )
            ->first();

        if ($plan === null) {
            $plan = $this->createMinimalPlanWithFeature(
                $featureCode,
            );
        }

        app(TenantSubscriptionService::class)->assignPlan(
            $tenantId,
            $plan->id,
        );
    }

    /**
     * Fallback kalau SubscriptionCatalogSeeder belum di-seed di test
     * ini — bikin plan+feature minimal sendiri supaya trait ini tidak
     * diam-diam bergantung ke seeder module lain.
     */
    private function createMinimalPlanWithFeature(
        string $featureCode,
    ): SubscriptionPlan {
        $feature = SubscriptionFeature::query()->firstOrCreate(
            ['code' => $featureCode],
            ['name' => $featureCode],
        );

        $plan = SubscriptionPlan::query()->create([
            'code' => sprintf(
                'test-plan-%s',
                $featureCode,
            ),
            'name' => sprintf(
                'Test Plan (%s)',
                $featureCode,
            ),
            'grace_period_days' => 30,
            'is_active' => true,
        ]);

        $plan->features()->attach(
            $feature->id,
        );

        return $plan;
    }
}
