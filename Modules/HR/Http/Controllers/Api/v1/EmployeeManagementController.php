<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Throwable;

final class EmployeeManagementController extends Controller
{
    private EmployeeRepositoryInterface $employeeRepository;
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection via Constructor (SOLID Principle compliance).
     */
    public function __construct(
        EmployeeRepositoryInterface $employeeRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->employeeRepository = $employeeRepository;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menampilkan daftar staf pegawai yang terisolasi khusus untuk sekolah/tenant aktif saat ini.
     */
    public function index(Request $request): JsonResponse
    {
        // Ekstrak ID Tenant secara aman dari atribut request hasil validasi token/kuki otentikasi
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $perPage = (int) $request->query('per_page', '15');

        if (! $tenantId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized. Tenant identification context missing.',
            ], 401);
        }

        $employees = $this->employeeRepository->getByTenantPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $employees->items(),
            'meta'   => [
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'per_page'     => $employees->perPage(),
                'total'        => $employees->total(),
            ]
        ], 200);
    }

    /**
     * Daftarkan profil staf pegawai baru terikat otomatis pada penyewa/tenant aktif.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized. Tenant identification context missing.',
            ], 401);
        }

        // 1. Lapisan Validasi Input Ketat (Fail-Fast)
        // Memastikan keunikan NIP hanya dikunci di dalam scope tabel pegawai
        $payload = $request->validate([
            'nip'     => ['required', 'string', 'max:50', 'alpha_num', 'unique:employees,nip'],
            'nama'    => ['required', 'string', 'max:255', 'min:3'],
            'email'   => ['required', 'email', 'max:255', 'unique:users,email'],
            'jabatan' => ['required', 'string', 'in:GURU,KEPALA_SEKOLAH,STAFF'],
        ]);

        try {
            // 2. Alihkan Eksekusi Penyimpanan Lintas Tabel ke Repositori Relasional
            $employee = $this->employeeRepository->createForTenant($tenantId, $payload);

            // 3. Rekam Aktivitas Manajemen ini ke Immutable Audit Trail System
            $this->auditTrail->log(
                'employee.created',
                sprintf('Berhasil mendaftarkan staf baru: %s dengan NIP: %s', $employee['name'], $employee['nip']),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Staf employee registered successfully within tenant domain.',
                'data'    => $employee
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to persist employee record. System transactional error.'
            ], 500);
        }
    }
}
