<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\Repository\PegawaiRepositoryInterface;
use Modules\Core\Contracts\Auth\AuditTrailServiceInterface;
use Throwable;

final class PegawaiManagementController extends Controller
{
    private PegawaiRepositoryInterface $pegawaiRepository;
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection via Constructor (SOLID Principle compliance).
     */
    public function __construct(
        PegawaiRepositoryInterface $pegawaiRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->pegawaiRepository = $pegawaiRepository;
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

        $pegawais = $this->pegawaiRepository->getByTenantPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $pegawais->items(),
            'meta'   => [
                'current_page' => $pegawais->currentPage(),
                'last_page'    => $pegawais->lastPage(),
                'per_page'     => $pegawais->perPage(),
                'total'        => $pegawais->total(),
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
            'nip'     => ['required', 'string', 'max:50', 'alpha_num', 'unique:pegawais,nip'],
            'nama'    => ['required', 'string', 'max:255', 'min:3'],
            'email'   => ['required', 'email', 'max:255', 'unique:users,email'],
            'jabatan' => ['required', 'string', 'in:GURU,KEPALA_SEKOLAH,STAFF'],
        ]);

        try {
            // 2. Alihkan Eksekusi Penyimpanan Lintas Tabel ke Repositori Relasional
            $pegawai = $this->pegawaiRepository->createForTenant($tenantId, $payload);

            // 3. Rekam Aktivitas Manajemen ini ke Immutable Audit Trail System
            $this->auditTrail->log(
                'pegawai.created',
                sprintf('Berhasil mendaftarkan staf baru: %s dengan NIP: %s', $pegawai['nama'], $pegawai['nip']),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Staf pegawai registered successfully within tenant domain.',
                'data'    => $pegawai
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to persist employee record. System transactional error.'
            ], 500);
        }
    }
}
