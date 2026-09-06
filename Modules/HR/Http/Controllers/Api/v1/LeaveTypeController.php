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
use Modules\HR\Http\Requests\StoreLeaveTypeRequest;
use Modules\HR\Http\Requests\UpdateLeaveTypeRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\LeaveTypeService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LeaveTypeController extends Controller
{
    public function __construct(
        private readonly LeaveTypeService $leaveTypeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $leaveTypes = LeaveType::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $leaveTypes,
        ]);
    }

    public function show(Request $request, string $leaveTypeId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $leaveType = LeaveType::query()->find($leaveTypeId);

        if ($leaveType === null) {
            return $this->notFoundResponse($leaveTypeId);
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveType,
        ]);
    }

    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     code: string,
         *     name: string,
         *     category: string,
         *     balance_mode: string,
         *     unit: string,
         *     description: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $leaveType = $this->leaveTypeService->createType($tenantId, $payload);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveType creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_TYPE_CREATION_FAILED',
                message: 'Failed to persist LeaveType record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveType,
        ], 201);
    }

    public function update(UpdateLeaveTypeRequest $request, string $leaveTypeId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $leaveType = $this->leaveTypeService->updateType($tenantId, $leaveTypeId, $payload);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($leaveTypeId);
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveType update failed.',
                [
                    'tenant_id' => $tenantId,
                    'leave_type_id' => $leaveTypeId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_TYPE_UPDATE_FAILED',
                message: 'Failed to update LeaveType record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveType,
        ]);
    }

    public function deactivate(Request $request, string $leaveTypeId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $leaveType = $this->leaveTypeService->deactivate($tenantId, $leaveTypeId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($leaveTypeId);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveType deactivation failed.',
                [
                    'tenant_id' => $tenantId,
                    'leave_type_id' => $leaveTypeId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_TYPE_DEACTIVATION_FAILED',
                message: 'Failed to deactivate LeaveType record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $leaveType,
        ]);
    }

    private function conflictResponse(LeaveLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_TYPE_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $leaveTypeId): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_TYPE_NOT_FOUND',
            message: sprintf(
                'LeaveType [%s] was not found in the current tenant.',
                $leaveTypeId,
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
