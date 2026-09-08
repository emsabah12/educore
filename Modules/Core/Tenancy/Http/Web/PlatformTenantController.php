<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionPlan;
use Modules\Core\Subscription\Models\TenantAddon;
use Modules\Core\Subscription\Models\TenantSubscription;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Modules\Core\Tenancy\Contracts\TenantRepositoryInterface;
use Modules\Core\Tenancy\Http\Requests\StoreTenantWithNewAdminRequest;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Services\TenantProvisioningService;
use Throwable;

/**
 * Panel Blade memanggil `TenantProvisioningService`/`TenantRepositoryInterface`
 * LANGSUNG di dalam proses PHP yang sama — TIDAK PERNAH lewat HTTP
 * self-call ke endpoint API-nya sendiri. `toggleStatus()` di bawah ini
 * sengaja memakai repository yang SAMA PERSIS dipakai
 * `TenantManagementController::update()` (API) — satu sumber kebenaran
 * untuk operasi ubah status tenant, dua permukaan (API dan Blade).
 */
final class PlatformTenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioningService,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly AuditTrailServiceInterface $auditTrail,
        private readonly TenantSubscriptionService $subscriptionService,
    ) {}

    public function index(): View
    {
        $tenants = Tenant::query()
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('platform.tenants.index', [
            'tenants' => $tenants,
        ]);
    }

    public function create(): View
    {
        return view('platform.tenants.create');
    }

    /**
     * Me-reuse `StoreTenantWithNewAdminRequest` YANG SAMA PERSIS dipakai
     * `POST /api/v1/core/tenants/with-new-admin` — satu aturan validasi,
     * dua permukaan (API dan Blade).
     */
    public function store(StoreTenantWithNewAdminRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $tenantData = [
            'name' => $validated['name'],
            'subdomain' => $validated['subdomain'],
            'is_active' => $validated['is_active'] ?? true,
        ];

        $adminData = [
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => $validated['admin_password'],
        ];

        try {
            $this->provisioningService->provisionWithNewAdmin(
                $tenantData,
                $adminData,
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Gagal mendaftarkan tenant. Silakan coba lagi.');
        }

        return redirect()
            ->route('platform.tenants.index')
            ->with('status', sprintf(
                'Tenant "%s" berhasil didaftarkan beserta admin awalnya.',
                $tenantData['name'],
            ));
    }

    /**
     * Halaman detail — menampilkan info tenant DAN riwayat aktivitas
     * (audit log) khusus untuk tenant ini, diambil dari `audit_logs`
     * yang SAMA PERSIS ditulis oleh `recordAuditSafely()` di API
     * controller. Belum ada Eloquent model untuk `audit_logs` (append
     * -only, ditulis lewat `DatabaseAuditTrailService::log()`), jadi
     * dibaca langsung lewat query builder — proporsional untuk
     * kebutuhan "log sederhana", bukan sistem pelaporan audit penuh.
     */
    public function show(string $tenantId): View
    {
        try {
            $tenant = $this->tenantRepository->findById($tenantId);
        } catch (ModelNotFoundException) {
            abort(404, 'Tenant tidak ditemukan.');
        }

        $this->subscriptionService->syncExpiredLocks($tenantId);

        $auditLogs = DB::table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'audit_page');

        $subscription = TenantSubscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->first();

        $availablePlans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $tenantAddons = TenantAddon::query()
            ->with('addon.feature')
            ->where('tenant_id', $tenantId)
            ->get();

        $assignedAddonIds = $tenantAddons->pluck('addon_id')->all();

        $availableAddonsToAdd = Addon::query()
            ->where('is_active', true)
            ->whereNotIn('id', $assignedAddonIds)
            ->orderBy('name')
            ->get();

        return view('platform.tenants.show', [
            'tenant' => $tenant,
            'auditLogs' => $auditLogs,
            'subscription' => $subscription,
            'availablePlans' => $availablePlans,
            'tenantAddons' => $tenantAddons,
            'availableAddonsToAdd' => $availableAddonsToAdd,
        ]);
    }

    /**
     * Aktifkan/nonaktifkan tenant — TIDAK menerima status target dari
     * client, selalu MEMBALIK status saat ini (dibaca ulang dari
     * database, bukan dari input form) supaya tidak ada race antara
     * apa yang tampil di layar dan apa yang benar-benar tersimpan.
     */
    public function toggleStatus(string $tenantId): RedirectResponse
    {
        try {
            $tenant = $this->tenantRepository->findById($tenantId);
            $newStatus = ! (bool) $tenant['is_active'];

            $updated = $this->tenantRepository->update($tenantId, [
                'is_active' => $newStatus,
            ]);
        } catch (ModelNotFoundException) {
            abort(404, 'Tenant tidak ditemukan.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Gagal memperbarui status tenant.');
        }

        $this->auditTrail->log(
            eventType: $newStatus ? 'tenant.activated' : 'tenant.deactivated',
            description: sprintf(
                'Superadmin %s tenant: %s',
                $newStatus ? 'mengaktifkan' : 'menonaktifkan',
                $updated['name'],
            ),
            tenantId: $tenantId,
            actorUserId: auth('web')->id() !== null ? (string) auth('web')->id() : null,
        );

        return redirect()
            ->route('platform.tenants.show', $tenantId)
            ->with('status', sprintf(
                'Status tenant berhasil diubah menjadi %s.',
                $newStatus ? 'Aktif' : 'Nonaktif',
            ));
    }
}
