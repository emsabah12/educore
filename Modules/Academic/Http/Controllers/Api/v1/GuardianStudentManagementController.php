<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Academic\Contracts\GuardianStudentRepositoryInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Throwable;

final class GuardianStudentManagementController extends Controller
{
    private GuardianStudentRepositoryInterface $pivotRepository;
    private AuditTrailServiceInterface $auditTrail;

    public function __construct(
        GuardianStudentRepositoryInterface $pivotRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->pivotRepository = $pivotRepository;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menampilkan daftar anak/santri berdasarkan ID Wali Santri tertentu.
     */
    public function index(Request $request, string $guardianId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $children = $this->pivotRepository->getStudentByGuardian($tenantId, $guardianId);

        return response()->json([
            'status' => 'success',
            'data'   => $children
        ], 200);
    }

    /**
     * Menautkan santri ke wali santri secara aman (Attach).
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $payload = $request->validate([
            'guardian_id' => ['required', 'string', 'uuid'],
            'student_id'     => ['required', 'string', 'uuid'],
            'hubungan'      => ['required', 'string', 'max:50']
        ]);

        try {
            $this->pivotRepository->attachStudentToGuardian(
                $tenantId,
                $payload['guardian_id'],
                $payload['student_id'],
                $payload['relation']
            );

            $this->auditTrail->log(
                'guardian.pivot.attached',
                sprintf('Berhasil memetakan wali %s ke anak %s', $payload['guardian_id'], $payload['student_id']),
                $tenantId,
                $operatorId,
                $payload
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Student successfully linked to guardian context.'
            ], 200);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * Memutus tautan santri dari wali santri (Detach).
     */
    public function destroy(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $operatorId = $request->attributes->get('authenticated_user_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $payload = $request->validate([
            'walisantri_id' => ['required', 'string', 'uuid'],
            'santri_id'     => ['required', 'string', 'uuid']
        ]);

        $detached = $this->pivotRepository->detachStudentToGuardian($tenantId, $payload['guardian_id'], $payload['student_id']);

        if (! $detached) {
            return response()->json(['status' => 'error', 'message' => 'Relation assignment record not found.'], 404);
        }

        $this->auditTrail->log(
            'walisantri.pivot.detached',
            sprintf('Memutus hubungan wali %s dari anak %s', $payload['walisantri_id'], $payload['santri_id']),
            $tenantId,
            $operatorId,
            $payload
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Student link detached from guardian successfully.'
        ], 200);
    }
}
