<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\Repository\WalisantriSantriRepositoryInterface;
use Modules\Core\Contracts\Auth\AuditTrailServiceInterface;
use Throwable;

final class WalisantriSantriManagementController extends Controller
{
    private WalisantriSantriRepositoryInterface $pivotRepository;
    private AuditTrailServiceInterface $auditTrail;

    public function __construct(
        WalisantriSantriRepositoryInterface $pivotRepository,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->pivotRepository = $pivotRepository;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menampilkan daftar anak/santri berdasarkan ID Wali Santri tertentu.
     */
    public function index(Request $request, string $walisantriId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $children = $this->pivotRepository->getSantriByWalisantri($tenantId, $walisantriId);

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
            'walisantri_id' => ['required', 'string', 'uuid'],
            'santri_id'     => ['required', 'string', 'uuid'],
            'hubungan'      => ['required', 'string', 'max:50']
        ]);

        try {
            $this->pivotRepository->attachSantri(
                $tenantId,
                $payload['walisantri_id'],
                $payload['santri_id'],
                $payload['hubungan']
            );

            $this->auditTrail->log(
                'walisantri.pivot.attached',
                sprintf('Berhasil memetakan wali %s ke anak %s', $payload['walisantri_id'], $payload['santri_id']),
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

        $detached = $this->pivotRepository->detachSantri($tenantId, $payload['walisantri_id'], $payload['santri_id']);

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
