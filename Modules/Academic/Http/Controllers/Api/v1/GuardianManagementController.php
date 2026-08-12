<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Academic\Contracts\GuardianRepositoryInterface;
use Modules\Academic\Http\Requests\StoreGuardianRequest;
use Modules\Academic\Services\GuardianProvisioningService;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Throwable;

final class GuardianManagementController extends Controller
{
    public function __construct(
        private readonly GuardianRepositoryInterface $guardianRepository,
        private readonly GuardianProvisioningService $guardianProvisioningService,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        $perPage = max(
            1,
            min(
                (int) $request->query('per_page', '15'),
                100,
            ),
        );

        $guardians = $this->guardianRepository
            ->getByTenantPaginated(
                $tenantId,
                $perPage,
            );

        return response()->json([
            'status' => 'success',
            'data' => $guardians->items(),
            'meta' => [
                'current_page' => $guardians->currentPage(),
                'last_page' => $guardians->lastPage(),
                'per_page' => $guardians->perPage(),
                'total' => $guardians->total(),
            ],
        ]);
    }

    public function store(StoreGuardianRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        /** @var array{nama:string,no_hp?:string|null} $payload */
        $payload = $request->validated();

        try {
            $guardian = $this->guardianProvisioningService
                ->provision(
                    tenantId: $tenantId,
                    data: $payload,
                );
        } catch (Throwable $exception) {
            Log::error(
                'Guardian provisioning failed.',
                [
                    'tenant_id' => $tenantId,
                    'operator_user_id' => is_string($operatorId)
                        ? $operatorId
                        : null,
                    'exception_class' => $exception::class,
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to persist guardian record.',
            ], 500);
        }

        $this->auditTrail->log(
            eventType: 'guardian.created',
            description: 'Created guardian profile.',
            tenantId: $tenantId,
            actorUserId: is_string($operatorId)
                ? $operatorId
                : null,
            metadata: [
                'guardian_id' => $guardian['guardian_id'],
                'membership_id' => $guardian['membership_id'],
                'person_id' => $guardian['person_id'],
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Guardian registered successfully within tenant domain.',
            'data' => $guardian,
        ], 201);
    }
}
