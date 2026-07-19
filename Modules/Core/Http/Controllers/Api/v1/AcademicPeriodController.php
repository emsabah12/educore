<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\Repository\AcademicPeriodRepositoryInterface;
use Modules\Core\Contracts\Auth\AuditTrailServiceInterface;
use Throwable;

final class AcademicPeriodController extends Controller
{
    private AcademicPeriodRepositoryInterface $periodRepository;
    private AuditTrailServiceInterface $auditTrail;

    public function __construct(
        AcademicPeriodRepositoryInterface $periodRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->periodRepository = $periodRepository;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menampilkan daftar tahun ajaran berlingkup tenant aktif.
     */
    public function indexYears(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $perPage = (int) $request->query('per_page', '15');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $years = $this->periodRepository->getYearsPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $years->items(),
            'meta'   => [
                'current_page' => $years->currentPage(),
                'last_page'    => $years->lastPage(),
                'per_page'     => $years->perPage(),
                'total'        => $years->total(),
            ]
        ], 200);
    }

    /**
     * Membuat tahun ajaran baru.
     */
    public function storeYear(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $payload = $request->validate([
            'name'       => ['required', 'string', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after:start_date'],
            'is_active'  => ['nullable', 'boolean']
        ]);

        try {
            $year = $this->periodRepository->createYearForTenant($tenantId, $payload);

            $this->auditTrail->log(
                'academic_year.created',
                sprintf('Berhasil membuat tahun ajaran baru: %s', $year['name']),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Academic year established successfully.',
                'data'    => $year
            ], 201);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to create academic year.'], 500);
        }
    }

    /**
     * Membuat semester baru terikat pada tahun ajaran tertentu.
     */
    public function storeSemester(Request $request, string $yearId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $payload = $request->validate([
            'name'      => ['required', 'string', 'max:50'],
            'type'      => ['required', 'string', 'in:GANJIL,GENAP,ganjil,genap'],
            'is_active' => ['nullable', 'boolean']
        ]);

        try {
            $semester = $this->periodRepository->createSemesterForTenant($tenantId, $yearId, $payload);

            $this->auditTrail->log(
                'academic_semester.created',
                sprintf('Berhasil membuat semester baru: %s', $semester['name']),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Academic semester established successfully.',
                'data'    => $semester
            ], 201);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
