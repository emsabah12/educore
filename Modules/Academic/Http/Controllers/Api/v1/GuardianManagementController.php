<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Academic\Contracts\GuardianRepositoryInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Throwable;

final class GuardianManagementController extends Controller
{
    private GuardianRepositoryInterface $guardianRepository;
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection via Constructor (SOLID Compliance).
     */
    public function __construct(
        GuardianRepositoryInterface $guardianRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->guardianRepository = $guardianRepository;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menampilkan daftar wali santri yang terisolasi per lembaga/tenant aktif saat ini.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $perPage = (int) $request->query('per_page', '15');

        if (! $tenantId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized. Tenant context identification missing.',
            ], 401);
        }

        $walisantris = $this->guardianRepository->getByTenantPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $walisantris->items(),
            'meta'   => [
                'current_page' => $walisantris->currentPage(),
                'last_page'    => $walisantris->lastPage(),
                'per_page'     => $walisantris->perPage(),
                'total'        => $walisantris->total(),
            ]
        ], 200);
    }

    /**
     * Mendaftarkan profil wali santri baru secara transaksional terisolasi.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized. Tenant context identification missing.',
            ], 401);
        }

        // Lapisan Validasi Fail-Fast
        $payload = $request->validate([
            'nama'  => ['required', 'string', 'max:255', 'min:3'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'no_hp' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
        ]);

        try {
            // Eksekusi penulisan atomik lintas tabel via repositori
            $guardian = $this->guardianRepository->createForTenant($tenantId, $payload);

            // Catat ke dalam sistem log audit audit trail
            $this->auditTrail->log(
                'walisantri.created',
                sprintf('Berhasil mendaftarkan wali santri baru: %s', $guardian['nama']),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Wali santri registered successfully within tenant domain.',
                'data'    => $guardian
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to persist guardian record. System transactional error.'
            ], 500);
        }
    }
}
