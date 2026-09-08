<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Throwable;

/**
 * Aksi ubah langganan SATU tenant tertentu — beda dari
 * `PlatformPlanController`/`PlatformAddonController` yang mengelola
 * KATALOG global. Semua aksi di sini memanggil
 * `TenantSubscriptionService` langsung (in-process), lalu mencatat
 * audit trail, konsisten dengan pola `PlatformTenantController`.
 */
final class PlatformTenantSubscriptionController extends Controller
{
    public function __construct(
        private readonly TenantSubscriptionService $subscriptionService,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    public function assignPlan(Request $request, string $tenantId): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('subscription_plans', 'id')],
        ]);

        try {
            $subscription = $this->subscriptionService->assignPlan($tenantId, $validated['plan_id']);
        } catch (ModelNotFoundException) {
            abort(404, 'Paket tidak ditemukan.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Gagal mengganti paket tenant.');
        }

        $planName = SubscriptionPlan::query()->find($subscription->plan_id)?->name ?? $subscription->plan_id;

        $this->auditTrail->log(
            eventType: 'tenant.plan_assigned',
            description: sprintf('Superadmin mengganti paket tenant menjadi: %s (status trial)', $planName),
            tenantId: $tenantId,
            actorUserId: $this->actorId(),
        );

        return redirect()
            ->route('platform.tenants.show', $tenantId)
            ->with('status', sprintf('Paket berhasil diganti menjadi "%s" (status trial).', $planName));
    }

    public function activatePlan(string $tenantId): RedirectResponse
    {
        try {
            $subscription = $this->subscriptionService->activatePlan($tenantId);
        } catch (ModelNotFoundException) {
            abort(404, 'Tenant belum memiliki paket.');
        }

        $planName = $subscription->plan?->name ?? $subscription->plan_id;

        $this->auditTrail->log(
            eventType: 'tenant.plan_activated',
            description: sprintf('Superadmin mengaktifkan paket tenant: %s', $planName),
            tenantId: $tenantId,
            actorUserId: $this->actorId(),
        );

        return redirect()
            ->route('platform.tenants.show', $tenantId)
            ->with('status', 'Paket tenant berhasil diaktifkan.');
    }

    public function assignAddon(Request $request, string $tenantId): RedirectResponse
    {
        $validated = $request->validate([
            'addon_id' => ['required', 'uuid', Rule::exists('addons', 'id')],
        ]);

        try {
            $tenantAddon = $this->subscriptionService->assignAddon($tenantId, $validated['addon_id']);
        } catch (ModelNotFoundException) {
            abort(404, 'Add-on tidak ditemukan.');
        }

        $addonName = Addon::query()->find($tenantAddon->addon_id)?->name ?? $tenantAddon->addon_id;

        $this->auditTrail->log(
            eventType: 'tenant.addon_assigned',
            description: sprintf('Superadmin menambahkan add-on untuk tenant: %s (status trial)', $addonName),
            tenantId: $tenantId,
            actorUserId: $this->actorId(),
        );

        return redirect()
            ->route('platform.tenants.show', $tenantId)
            ->with('status', sprintf('Add-on "%s" berhasil ditambahkan (status trial).', $addonName));
    }

    public function activateAddon(string $tenantId, string $addonId): RedirectResponse
    {
        try {
            $tenantAddon = $this->subscriptionService->activateAddon($tenantId, $addonId);
        } catch (ModelNotFoundException) {
            abort(404, 'Add-on tenant tidak ditemukan.');
        }

        $addonName = $tenantAddon->addon?->name ?? $addonId;

        $this->auditTrail->log(
            eventType: 'tenant.addon_activated',
            description: sprintf('Superadmin mengaktifkan add-on tenant: %s', $addonName),
            tenantId: $tenantId,
            actorUserId: $this->actorId(),
        );

        return redirect()
            ->route('platform.tenants.show', $tenantId)
            ->with('status', sprintf('Add-on "%s" berhasil diaktifkan.', $addonName));
    }

    public function revokeAddon(string $tenantId, string $addonId): RedirectResponse
    {
        try {
            $tenantAddon = $this->subscriptionService->revokeAddon($tenantId, $addonId);
        } catch (ModelNotFoundException) {
            abort(404, 'Add-on tenant tidak ditemukan.');
        }

        $addonName = $tenantAddon->addon?->name ?? $addonId;

        $this->auditTrail->log(
            eventType: 'tenant.addon_revoked',
            description: sprintf(
                'Superadmin mencabut add-on tenant: %s (read-only sampai %s)',
                $addonName,
                $tenantAddon->readonly_until?->format('d M Y') ?? '-',
            ),
            tenantId: $tenantId,
            actorUserId: $this->actorId(),
        );

        return redirect()
            ->route('platform.tenants.show', $tenantId)
            ->with('status', sprintf('Add-on "%s" berhasil dicabut (masuk masa tenggang read-only).', $addonName));
    }

    private function actorId(): ?string
    {
        $id = auth('web')->id();

        return $id !== null ? (string) $id : null;
    }
}
