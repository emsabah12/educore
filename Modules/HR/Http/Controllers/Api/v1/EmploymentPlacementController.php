<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Http\Controllers\Concerns\ChecksHrResourceScope;
use Modules\HR\Http\Requests\StoreEmploymentPlacementRequest;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Services\EmploymentPlacementService;
use Modules\HR\Services\HrWorkforceScopeService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EmploymentPlacementController extends Controller
{
    use ChecksHrResourceScope;

    public function __construct(
        private readonly EmploymentPlacementService $employmentPlacementService,
        private readonly AuditTrailServiceInterface $auditTrail,
        private readonly HrWorkforceScopeService $hrWorkforceScopeService,
        private readonly OrganizationalContextInterface $organizationalContext,
    ) {}

    public function index(
        Request $request,
        string $employmentId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $perPage = max(
            1,
            min(
                (int) $request->query('per_page', '15'),
                100,
            ),
        );

        $placements = EmploymentPlacement::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $placements->items(),
            'meta' => [
                'current_page' => $placements->currentPage(),
                'last_page' => $placements->lastPage(),
                'per_page' => $placements->perPage(),
                'total' => $placements->total(),
            ],
        ]);
    }

    public function store(
        StoreEmploymentPlacementRequest $request,
        string $employmentId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $operatorId = $this->isCanonicalUuid($operatorId)
            ? $operatorId
            : null;

        $employeeId = DB::table('employments')
            ->where('id', $employmentId)
            ->where('tenant_id', $tenantId)
            ->value('employee_id');

        if ($employeeId !== null) {
            $scopeDeniedResponse = $this->scopeDeniedResponseIfEmployeeNotVisible(
                $tenantId,
                $employeeId,
            );

            if ($scopeDeniedResponse !== null) {
                return $scopeDeniedResponse;
            }
        }

        /**
         * @var array{
         *     organizational_assignment_id: string,
         *     effective_from: string,
         *     is_primary?: bool,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $placement = $this->employmentPlacementService->createPlacement(
                tenantId: $tenantId,
                employmentId: $employmentId,
                data: $payload,
            );
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_OR_ASSIGNMENT_NOT_FOUND',
                message: sprintf(
                    'Employment [%s] or the referenced OrganizationalAssignment was not found in the current tenant.',
                    $employmentId,
                ),
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (EmploymentLifecycleException $exception) {
            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_PLACEMENT_CONFLICT',
                message: $exception->getMessage(),
                status: Response::HTTP_CONFLICT,
            );
        } catch (Throwable $exception) {
            Log::error(
                'EmploymentPlacement creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'employment_id' => $employmentId,
                    'operator_user_id' => $operatorId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_PLACEMENT_CREATION_FAILED',
                message: 'Failed to persist EmploymentPlacement record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            $this->auditTrail->log(
                eventType: 'employment.placement.created',
                description: 'Created EmploymentPlacement.',
                tenantId: $tenantId,
                actorUserId: $operatorId,
                metadata: [
                    'employment_placement_id' => $placement->id,
                    'employment_id' => $placement->employment_id,
                    'organizational_assignment_id' => $placement->organizational_assignment_id,
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'EmploymentPlacement created.',
            'data' => $placement,
        ], 201);
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isCanonicalUuid(mixed $value): bool
    {
        return is_string($value)
            && Str::isUuid(trim($value));
    }

    private function authenticationContextDeniedResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_CONTEXT_DENIED',
            message: 'Authentication context missing or invalid.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
