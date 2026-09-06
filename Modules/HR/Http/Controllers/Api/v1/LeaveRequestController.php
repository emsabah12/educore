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
use Modules\HR\Http\Requests\DecideLeaveRequestRequest;
use Modules\HR\Http\Requests\StoreLeaveRequestRequest;
use Modules\HR\Http\Requests\UpdateLeaveRequestRequest;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Services\LeaveCancellationService;
use Modules\HR\Services\LeaveRequestService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $requestService,
        private readonly LeaveCancellationService $cancellationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $perPage = max(1, min((int) $request->query('per_page', '15'), 100));

        $query = LeaveRequest::query()->orderByDesc('created_at');

        $employmentId = $request->query('employment_id');

        if (is_string($employmentId) && Str::isUuid($employmentId)) {
            $query->where('employment_id', $employmentId);
        }

        $requests = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function show(Request $request, string $leaveRequestId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $leaveRequest = LeaveRequest::query()->with('approvalSteps', 'entitlementAllocations')->find($leaveRequestId);

        if ($leaveRequest === null) {
            return $this->notFoundResponse($leaveRequestId);
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     employment_id: string,
         *     leave_type_id: string,
         *     starts_at: string,
         *     ends_at: string,
         *     request_timezone: string,
         *     requested_units: string,
         *     reason: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $leaveRequest = $this->requestService->createDraft(
                $tenantId,
                $payload['employment_id'],
                $payload['leave_type_id'],
                $payload['starts_at'],
                $payload['ends_at'],
                $payload['request_timezone'],
                $payload['requested_units'],
                $payload['reason'] ?? null,
            );
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'LEAVE_TYPE_NOT_FOUND',
                message: 'Referenced LeaveType was not found in the current tenant.',
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (Throwable $exception) {
            Log::error(
                'LeaveRequest creation failed.',
                ['tenant_id' => $tenantId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_REQUEST_CREATION_FAILED',
                message: 'Failed to persist LeaveRequest record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ], 201);
    }

    public function update(UpdateLeaveRequestRequest $request, string $leaveRequestId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $leaveRequest = $this->requestService->updateDraft($tenantId, $leaveRequestId, $payload);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($leaveRequestId);
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveRequest update failed.',
                ['tenant_id' => $tenantId, 'leave_request_id' => $leaveRequestId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_REQUEST_UPDATE_FAILED',
                message: 'Failed to update LeaveRequest record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    public function submit(Request $request, string $leaveRequestId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $leaveRequest = $this->requestService->submit($tenantId, $leaveRequestId, $membershipId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($leaveRequestId);
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveRequest submit failed.',
                ['tenant_id' => $tenantId, 'leave_request_id' => $leaveRequestId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_REQUEST_SUBMIT_FAILED',
                message: 'Failed to submit LeaveRequest.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    public function withdraw(Request $request, string $leaveRequestId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $leaveRequest = $this->requestService->withdraw($tenantId, $leaveRequestId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($leaveRequestId);
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveRequest withdraw failed.',
                ['tenant_id' => $tenantId, 'leave_request_id' => $leaveRequestId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_REQUEST_WITHDRAW_FAILED',
                message: 'Failed to withdraw LeaveRequest.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    public function cancel(DecideLeaveRequestRequest $request, string $leaveRequestId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array{note: string|null} $payload */
        $payload = $request->validated();

        try {
            $leaveRequest = $this->cancellationService->cancelApproved(
                $tenantId,
                $leaveRequestId,
                $membershipId,
                $payload['note'] ?? null,
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($leaveRequestId);
        } catch (LeaveLifecycleException $exception) {
            return $this->cancellationConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveRequest cancellation failed.',
                ['tenant_id' => $tenantId, 'leave_request_id' => $leaveRequestId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_REQUEST_CANCELLATION_FAILED',
                message: 'Failed to cancel LeaveRequest.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    private function conflictResponse(LeaveLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_REQUEST_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function cancellationConflictResponse(LeaveLifecycleException $exception): JsonResponse
    {
        $message = $exception->getMessage();

        $status = str_contains($message, 'LEAVE_CANCELLATION_NOT_ALLOWED') && str_contains($message, 'permission')
            ? Response::HTTP_FORBIDDEN
            : Response::HTTP_CONFLICT;

        return ApiErrorResponse::make(
            code: 'LEAVE_REQUEST_CANCELLATION_CONFLICT',
            message: $message,
            status: $status,
        );
    }

    private function notFoundResponse(string $leaveRequestId): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_REQUEST_NOT_FOUND',
            message: sprintf('Leave Request [%s] was not found in the current tenant.', $leaveRequestId),
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
