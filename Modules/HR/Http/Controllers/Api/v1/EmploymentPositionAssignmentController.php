<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Http\Requests\StoreEmploymentPositionAssignmentRequest;
use Modules\HR\Models\EmploymentPositionAssignment;
use Modules\HR\Services\EmploymentPositionAssignmentService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EmploymentPositionAssignmentController extends Controller
{
    public function __construct(
        private readonly EmploymentPositionAssignmentService $employmentPositionAssignmentService,
        private readonly AuditTrailServiceInterface $auditTrail,
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

        $assignments = EmploymentPositionAssignment::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $assignments->items(),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
            ],
        ]);
    }

    public function store(
        StoreEmploymentPositionAssignmentRequest $request,
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

        /**
         * @var array{
         *     position_id: string,
         *     employment_placement_id: string|null,
         *     effective_from: string,
         *     is_primary?: bool,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $assignment = $this->employmentPositionAssignmentService->createAssignment(
                tenantId: $tenantId,
                employmentId: $employmentId,
                data: $payload,
            );
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_NOT_FOUND',
                message: sprintf(
                    'Employment [%s] was not found in the current tenant.',
                    $employmentId,
                ),
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (EmploymentLifecycleException $exception) {
            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_POSITION_ASSIGNMENT_CONFLICT',
                message: $exception->getMessage(),
                status: Response::HTTP_CONFLICT,
            );
        } catch (Throwable $exception) {
            Log::error(
                'EmploymentPositionAssignment creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'employment_id' => $employmentId,
                    'operator_user_id' => $operatorId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_POSITION_ASSIGNMENT_CREATION_FAILED',
                message: 'Failed to persist EmploymentPositionAssignment record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            $this->auditTrail->log(
                eventType: 'employment.position_assignment.created',
                description: 'Created EmploymentPositionAssignment.',
                tenantId: $tenantId,
                actorUserId: $operatorId,
                metadata: [
                    'employment_position_assignment_id' => $assignment->id,
                    'employment_id' => $assignment->employment_id,
                    'position_id' => $assignment->position_id,
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'EmploymentPositionAssignment created.',
            'data' => $assignment,
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
