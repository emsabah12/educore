<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Modules\Core\Tenancy\Http\Requests\StoreTenantWithNewAdminRequest;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Services\TenantProvisioningService;
use Throwable;

/**
 * Panel Blade memanggil `TenantProvisioningService` LANGSUNG di dalam
 * proses PHP yang sama — TIDAK PERNAH lewat HTTP self-call ke endpoint
 * API-nya sendiri. Itu sebabnya kita sengaja meletakkan panel di
 * codebase yang SAMA (bukan backend terpisah): logika bisnisnya bisa
 * dipanggil langsung tanpa biaya jaringan atau duplikasi otorisasi.
 */
final class PlatformTenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioningService,
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
}
