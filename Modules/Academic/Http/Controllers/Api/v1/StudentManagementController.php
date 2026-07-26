<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Academic\Contracts\Repository\StudentRepositoryInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Throwable;

final class StudentManagementController extends Controller
{
    private StudentRepositoryInterface $studentRepository;
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection via Constructor.
     */
    public function __construct(
        StudentRepositoryInterface $StudentRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->studentRepository = $StudentRepository;
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

        $student = $this->studentRepository->getByTenantPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $student->items(),
            'meta'   => [
                'current_page' => $student->currentPage(),
                'last_page'    => $student->lastPage(),
                'per_page'     => $student->perPage(),
                'total'        => $student->total(),
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
            $student = $this->studentRepository->createForTenant($tenantId, $payload);

            // Audit logging tracking otomatis
            $this->auditTrail->log(
                'santri.created',
                sprintf('Berhasil mendaftarkan santri baru: %s dengan NIS: %s', $student['nama'], $student['nis'] ?? '-'),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Staf santri registered successfully within tenant domain.',
                'data'    => $student
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to persist student record. Transactional system failure.'
            ], 500);
        }
    }
}
