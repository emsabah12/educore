<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Models\TenantAddon;
use Modules\Core\Subscription\Models\TenantSubscription;

/**
 * §PRD Subscription & Custom Role — inti logika bisnis.
 *
 * KEPUTUSAN CAKUPAN: siklus grace-period (locked_readonly ->
 * locked_hidden) berlaku SPESIFIK saat add-on dicabut/di-downgrade,
 * konsisten dengan skema `tenant_addons` (satu-satunya tabel yang
 * punya kolom `locked_at`/`readonly_until`). Ganti PAKET yang
 * menghilangkan fitur bawaannya berefek LANGSUNG tanpa masa tenggang
 * — beda karakter dari mencabut satu add-on.
 */
final class TenantSubscriptionService
{
    /**
     * Menetapkan/mengganti paket tenant. Status awal SELALU `trial`
     * ("berlaku self-service penuh... sambil menunggu
     * pembayaran/approval") — jangan panggil `activatePlan()` di sini,
     * itu langkah terpisah yang eksplisit.
     */
    public function assignPlan(string $tenantId, string $planId): TenantSubscription
    {
        $plan = SubscriptionPlan::query()->findOrFail($planId);

        return DB::transaction(function () use ($tenantId, $plan): TenantSubscription {
            return TenantSubscription::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'plan_id' => $plan->id,
                    'status' => TenantSubscription::STATUS_TRIAL,
                    'trial_ends_at' => null,
                    'activated_at' => null,
                ],
            );
        });
    }

    /**
     * Dipanggil superadmin setelah pembayaran/approval selesai.
     */
    public function activatePlan(string $tenantId): TenantSubscription
    {
        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $subscription->update([
            'status' => TenantSubscription::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        return $subscription->refresh();
    }

    /**
     * Mengaktifkan add-on untuk tenant — kalau sebelumnya PERNAH ada
     * (mis. dulu di-lock, sekarang diaktifkan ulang), baris yang sama
     * dipakai ulang (`updateOrCreate` berdasar `tenant_id`+`addon_id`
     * yang memang unik) — kolom kunci direset ke kosong.
     */
    public function assignAddon(string $tenantId, string $addonId): TenantAddon
    {
        $addon = Addon::query()->findOrFail($addonId);

        return TenantAddon::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'addon_id' => $addon->id],
            [
                'status' => TenantAddon::STATUS_TRIAL,
                'locked_at' => null,
                'readonly_until' => null,
            ],
        );
    }

    public function activateAddon(string $tenantId, string $addonId): TenantAddon
    {
        $tenantAddon = TenantAddon::query()
            ->where('tenant_id', $tenantId)
            ->where('addon_id', $addonId)
            ->firstOrFail();

        $tenantAddon->update([
            'status' => TenantAddon::STATUS_ACTIVE,
        ]);

        return $tenantAddon->refresh();
    }

    /**
     * Mencabut add-on — masuk `locked_readonly` selama
     * `grace_period_days` MILIK PAKET TENANT SAAT INI (dibekukan di
     * `readonly_until`, TIDAK dihitung ulang kalau paket/grace period
     * berubah belakangan — lihat catatan di migrasi `tenant_addons`).
     */
    public function revokeAddon(string $tenantId, string $addonId): TenantAddon
    {
        $tenantAddon = TenantAddon::query()
            ->where('tenant_id', $tenantId)
            ->where('addon_id', $addonId)
            ->firstOrFail();

        $gracePeriodDays = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->with('plan')
            ->first()
            ?->plan
            ?->grace_period_days ?? 30;

        $lockedAt = now();

        $tenantAddon->update([
            'status' => TenantAddon::STATUS_LOCKED_READONLY,
            'locked_at' => $lockedAt,
            'readonly_until' => $lockedAt->clone()->addDays($gracePeriodDays),
        ]);

        return $tenantAddon->refresh();
    }

    /**
     * Memindahkan add-on yang masa `locked_readonly`-nya SUDAH LEWAT
     * ke `locked_hidden` — dipanggil lazy setiap kali data tenant
     * dibaca (belum ada scheduled job terpisah; itu bisa ditambahkan
     * nanti sebagai `php artisan schedule` task tanpa mengubah service
     * ini).
     */
    public function syncExpiredLocks(string $tenantId): void
    {
        TenantAddon::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantAddon::STATUS_LOCKED_READONLY)
            ->where('readonly_until', '<=', Carbon::now())
            ->update(['status' => TenantAddon::STATUS_LOCKED_HIDDEN]);
    }

    /**
     * Gabungan (union) kode fitur yang SUNGGUH efektif untuk tenant
     * ini SEKARANG — fitur bawaan paket AKTIF, ditambah fitur dari
     * setiap add-on berstatus `trial`/`active` (BUKAN `locked_*`).
     *
     * Inilah yang nanti dipakai Step D untuk memutuskan apakah role
     * kustom tenant masih berfungsi.
     *
     * @return array<int, string>
     */
    public function effectiveFeatureCodes(string $tenantId): array
    {
        $this->syncExpiredLocks($tenantId);

        $planFeatureCodes = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->with('plan.features')
            ->first()
            ?->plan
            ?->features
            ?->pluck('code')
            ?->all() ?? [];

        $addonFeatureCodes = TenantAddon::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [TenantAddon::STATUS_TRIAL, TenantAddon::STATUS_ACTIVE])
            ->with('addon.feature')
            ->get()
            ->pluck('addon.feature.code')
            ->filter()
            ->all();

        return array_values(array_unique([...$planFeatureCodes, ...$addonFeatureCodes]));
    }
}
