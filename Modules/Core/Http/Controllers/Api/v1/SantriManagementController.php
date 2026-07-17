<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\Repository\SantriRepositoryInterface;
use Modules\Core\Contracts\Auth\AuditTrailServiceInterface;
use Throwable;

final class SantriManagementController extends Controller
{
    private SantriRepositoryInterface $santriRepository;
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection via Constructor.
     */
    public function __construct(
        SantriRepositoryInterface $santriRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->santriRepository = $santriRepository;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menampilkan daftar santri terisolasi per-tenant aktif.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $perPage = (int) $request->query('per_page', '15');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $santris = $this->santriRepository->getByTenantPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $santris->items(),
            'meta'   => [
                'current_page' => $santris->currentPage(),
                'last_page'    => $santris->lastPage(),
                'per_page'     => $santris->perPage(),
                'total'        => $santris->total(),
            ]
        ], 200);
    }

    /**
     * Mendaftarkan data profil santri/siswa baru secara aman.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        // Validasi input ketat (Fail-Fast)
        $payload = $request->validate([
            'class_id' => ['required', 'string', 'uuid'],
            'nama'     => ['required', 'string', 'max:255', 'min:3'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'nis'      => ['nullable', 'string', 'max:50'],
            'nisn'     => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $santri = $this->santriRepository->createForTenant($tenantId, $payload);

            // Audit logging tracking otomatis
            $this->auditTrail->log(
                'santri.created',
                sprintf('Berhasil mendaftarkan santri baru: %s dengan NIS: %s', $santri['nama'], $santri['nis'] ?? '-'),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Staf santri registered successfully within tenant domain.',
                'data'    => $santri
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to persist student record. Transactional system failure.'
            ], 500);
        }
    }
}
