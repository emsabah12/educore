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
use Modules\HR\Http\Requests\StoreLeaveEntitlementPolicyRequest;
use Modules\HR\Models\LeaveEntitlementPolicy;
use Modules\HR\Services\LeaveEntitlementPolicyService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LeaveEntitlementPolicyController extends Controller
{
    public function __construct(
        private readonly LeaveEntitlementPolicyService $entitlementPolicyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $policies = LeaveEntitlementPolicy::query()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $policies,
        ]);
    }

    public function show(Request $request, string $entitlementPolicyId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $policy = LeaveEntitlementPolicy::query()->find($entitlementPolicyId);

        if ($policy === null) {
            return $this->notFoundResponse($entitlementPolicyId);
        }

        return response()->json([
            'status' => 'success',
            'data' => $policy,
        ]);
    }

    public function store(StoreLeaveEntitlementPolicyRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $policy = $this->entitlementPolicyService->createPolicy($tenantId, $payload);
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'LEAVE_TYPE_NOT_FOUND',
                message: 'Referenced LeaveType was not found in the current tenant.',
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveEntitlementPolicy creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_ENTITLEMENT_POLICY_CREATION_FAILED',
                message: 'Failed to persist LeaveEntitlementPolicy record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $policy,
        ], 201);
    }

    public function deactivate(Request $request, string $entitlementPolicyId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $policy = $this->entitlementPolicyService->deactivate($tenantId, $entitlementPolicyId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($entitlementPolicyId);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveEntitlementPolicy deactivation failed.',
                [
                    'tenant_id' => $tenantId,
                    'entitlement_policy_id' => $entitlementPolicyId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_ENTITLEMENT_POLICY_DEACTIVATION_FAILED',
                message: 'Failed to deactivate LeaveEntitlementPolicy record.',
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
            code: 'LEAVE_ENTITLEMENT_POLICY_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $entitlementPolicyId): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_ENTITLEMENT_POLICY_NOT_FOUND',
            message: sprintf(
                'LeaveEntitlementPolicy [%s] was not found in the current tenant.',
                $entitlementPolicyId,
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
