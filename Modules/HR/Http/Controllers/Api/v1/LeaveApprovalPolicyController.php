<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Http\Requests\StoreLeaveApprovalPolicyRequest;
use Modules\HR\Models\LeaveApprovalPolicy;
use Modules\HR\Services\LeaveApprovalPolicyService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LeaveApprovalPolicyController extends Controller
{
    public function __construct(
        private readonly LeaveApprovalPolicyService $approvalPolicyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $policies = LeaveApprovalPolicy::query()
            ->with('steps')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $policies,
        ]);
    }

    public function show(Request $request, string $approvalPolicyId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $policy = LeaveApprovalPolicy::query()->with('steps')->find($approvalPolicyId);

        if ($policy === null) {
            return $this->notFoundResponse($approvalPolicyId);
        }

        return response()->json([
            'status' => 'success',
            'data' => $policy,
        ]);
    }

    public function store(StoreLeaveApprovalPolicyRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validated();
        $steps = $payload['steps'] ?? [];
        unset($payload['steps']);

        try {
            $policy = $this->approvalPolicyService->createPolicyVersionWithSteps($tenantId, $payload, $steps);
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveApprovalPolicy creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_APPROVAL_POLICY_CREATION_FAILED',
                message: 'Failed to persist LeaveApprovalPolicy record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $policy,
        ], 201);
    }

    public function deactivate(Request $request, string $approvalPolicyId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $policy = $this->approvalPolicyService->deactivate($tenantId, $approvalPolicyId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($approvalPolicyId);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveApprovalPolicy deactivation failed.',
                [
                    'tenant_id' => $tenantId,
                    'approval_policy_id' => $approvalPolicyId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_APPROVAL_POLICY_DEACTIVATION_FAILED',
                message: 'Failed to deactivate LeaveApprovalPolicy record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $policy,
        ]);
    }

    private function conflictResponse(LeaveLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_APPROVAL_POLICY_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $approvalPolicyId): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_APPROVAL_POLICY_NOT_FOUND',
            message: sprintf(
                'LeaveApprovalPolicy [%s] was not found in the current tenant.',
                $approvalPolicyId,
            ),
            status: Response::HTTP_NOT_FOUND,
        );
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
