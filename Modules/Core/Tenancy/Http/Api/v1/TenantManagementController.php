<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Tenancy\Contracts\TenantRepositoryInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

final class TenantManagementController extends Controller
{
    private TenantRepositoryInterface $tenantRepository;
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection via Constructor (SOLID Compliance).
     */
    public function __construct(
        TenantRepositoryInterface $tenantRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->tenantRepository = $tenantRepository;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menampilkan daftar seluruh tenant dengan paginasi.
     * Diakses oleh Global Superadmin.
     */
    public function index(Request $request): JsonResponse
    {
        // Catatan: Proteksi Role Guard diletakkan di tingkatan routing middleware demi fleksibilitas
        $perPage = (int) $request->query('per_page', '15');
        $tenants = $this->tenantRepository->getAllPaginated($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $tenants->items(),
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ]
        ], 200);
    }

    /**
     * Membuat lembaga sekolah/tenant baru dalam ekosistem SaaS.
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validasi Input Ketat (Fail-Fast)
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:3'],
            'subdomain' => ['required', 'string', 'alpha_dash', 'max:50', 'unique:tenants,subdomain'],
            'is_active' => ['boolean']
        ]);

        try {
            // 2. Eksekusi Pembuatan Data via Repository
            $tenant = $this->tenantRepository->create($payload);

            // 3. Catat Aktivitas Penting ke Audit Trail
            $operatorId = $request->attributes->get('authenticated_user_id');
            $this->auditTrail->log(
                'tenant.created',
                sprintf('Superadmin berhasil mendaftarkan tenant baru: %s (%s)', $tenant['name'], $tenant['subdomain']),
                $tenant['id'],
                $operatorId,
                $payload
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Tenant registered successfully.',
                'data' => $tenant
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to register tenant. Internal System Failure.'
            ], 500);
        }
    }

    /**
     * Memperbarui data atau status keaktifan tenant.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['string', 'max:255', 'min:3'],
            'is_active' => ['boolean']
        ]);

        try {
            $updatedTenant = $this->tenantRepository->update($id, $payload);

            $operatorId = $request->attributes->get('authenticated_user_id');
            $this->auditTrail->log(
                'tenant.updated',
                sprintf('Superadmin memperbarui data tenant ID: %s', $id),
                $id,
                $operatorId,
                $payload
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Tenant updated successfully.',
                'data' => $updatedTenant
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update tenant.'
            ], 500);
        }
    }
}
