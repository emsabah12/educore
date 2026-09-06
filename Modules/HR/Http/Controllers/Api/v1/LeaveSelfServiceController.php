<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Http\Requests\StoreSelfLeaveRequestRequest;
use Modules\HR\Services\LeaveSelfService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * §15.7 — Self-service. Employee identity SELALU diresolusi dari
 * Membership terautentikasi lewat `LeaveSelfService` — tidak satu pun
 * method di controller ini menerima employment_id/employee_id dari
 * client.
 */
final class LeaveSelfServiceController extends Controller
{
    public function __construct(
        private readonly LeaveSelfService $selfService,
    ) {}

    public function balances(Request $request): JsonResponse
    {
        [$tenantId, $membershipId, $deniedResponse] = $this->authenticatedContext($request);

        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        try {
            $balances = $this->selfService->ownBalances($tenantId, $membershipId);
        } catch (LeaveLifecycleException $exception) {
            return $this->noActiveEmploymentResponse($exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $balances,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [$tenantId, $membershipId, $deniedResponse] = $this->authenticatedContext($request);

        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        try {
            $history = $this->selfService->ownHistory($tenantId, $membershipId);
        } catch (LeaveLifecycleException $exception) {
            return $this->noActiveEmploymentResponse($exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $history,
        ]);
    }

    public function show(Request $request, string $leaveRequestId): JsonResponse
    {
        [$tenantId, $membershipId, $deniedResponse] = $this->authenticatedContext($request);

        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        try {
            $leaveRequest = $this->selfService->findOwn($tenantId, $membershipId, $leaveRequestId);
        } catch (LeaveLifecycleException $exception) {
            return $this->notOwnedOrNoEmploymentResponse($exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    public function store(StoreSelfLeaveRequestRequest $request): JsonResponse
    {
        [$tenantId, $membershipId, $deniedResponse] = $this->authenticatedContext($request);

        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        /**
         * @var array{
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
            $leaveRequest = $this->selfService->createOwnDraft(
                $tenantId,
                $membershipId,
                $payload['leave_type_id'],
                $payload['starts_at'],
                $payload['ends_at'],
                $payload['request_timezone'],
                $payload['requested_units'],
                $payload['reason'] ?? null,
            );
        } catch (LeaveLifecycleException $exception) {
            return $this->noActiveEmploymentResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'Self-service Leave Request creation failed.',
                ['tenant_id' => $tenantId, 'membership_id' => $membershipId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_SELF_REQUEST_CREATION_FAILED',
                message: 'Failed to persist Leave Request.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ], 201);
    }

    public function submit(Request $request, string $leaveRequestId): JsonResponse
    {
        [$tenantId, $membershipId, $deniedResponse] = $this->authenticatedContext($request);

        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        try {
            $leaveRequest = $this->selfService->submitOwn($tenantId, $membershipId, $leaveRequestId);
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictOrNotOwnedResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'Self-service Leave Request submit failed.',
                ['tenant_id' => $tenantId, 'leave_request_id' => $leaveRequestId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_SELF_REQUEST_SUBMIT_FAILED',
                message: 'Failed to submit Leave Request.',
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
        [$tenantId, $membershipId, $deniedResponse] = $this->authenticatedContext($request);

        if ($deniedResponse !== null) {
            return $deniedResponse;
        }

        try {
            $leaveRequest = $this->selfService->withdrawOwn($tenantId, $membershipId, $leaveRequestId);
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictOrNotOwnedResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'Self-service Leave Request withdraw failed.',
                ['tenant_id' => $tenantId, 'leave_request_id' => $leaveRequestId, 'exception_class' => $exception::class],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_SELF_REQUEST_WITHDRAW_FAILED',
                message: 'Failed to withdraw Leave Request.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveRequest,
        ]);
    }

    /**
     * `LEAVE_SELF_REQUEST_NOT_OWNED` dilaporkan 404 (bukan 403) — dari
     * sudut pandang aktor, request milik orang lain memang "tidak ada"
     * di ruang lingkup self-service-nya, bukan sesuatu yang boleh
     * mereka tahu keberadaannya lalu ditolak aksesnya.
     */
    private function notOwnedOrNoEmploymentResponse(LeaveLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_SELF_REQUEST_NOT_FOUND',
            message: $exception->getMessage(),
            status: Response::HTTP_NOT_FOUND,
        );
    }

    private function conflictOrNotOwnedResponse(LeaveLifecycleException $exception): JsonResponse
    {
        if (str_contains($exception->getMessage(), 'LEAVE_SELF_REQUEST_NOT_OWNED')) {
            return $this->notOwnedOrNoEmploymentResponse($exception);
        }

        return ApiErrorResponse::make(
            code: 'LEAVE_SELF_REQUEST_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function noActiveEmploymentResponse(LeaveLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_SELF_NO_ACTIVE_EMPLOYMENT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: JsonResponse|null}
     */
    private function authenticatedContext(Request $request): array
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return ['', '', $this->authenticationContextDeniedResponse()];
        }

        return [$tenantId, $membershipId, null];
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
