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
use Modules\HR\Http\Requests\AdjustLeaveEntitlementRequest;
use Modules\HR\Http\Requests\GenerateLeaveEntitlementRequest;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Services\LeaveBalanceService;
use Modules\HR\Services\LeaveEntitlementService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LeaveEntitlementController extends Controller
{
    public function __construct(
        private readonly LeaveEntitlementService $entitlementService,
        private readonly LeaveBalanceService $balanceService,
    ) {}

    /**
     * §15.3 — GET /employees/{employeeId}/leave-balances. Employee bisa
     * punya beberapa Employment (episode kerja); saldo dikumpulkan
     * lintas SEMUA entitlement milik SEMUA Employment employee ini.
     */
    public function employeeBalances(Request $request, string $employeeId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            return ApiErrorResponse::make(
                code: 'EMPLOYEE_NOT_FOUND',
                message: sprintf('Employee [%s] was not found in the current tenant.', $employeeId),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $employmentIds = $employee->employments()->pluck('id');

        $entitlements = LeaveEntitlement::query()
            ->whereIn('employment_id', $employmentIds)
            ->orderByDesc('period_start')
            ->get();

        $balances = $entitlements->map(fn (LeaveEntitlement $entitlement): array => [
            'entitlement_id' => $entitlement->id,
            'employment_id' => $entitlement->employment_id,
            'leave_type_id' => $entitlement->leave_type_id,
            'period_start' => $entitlement->period_start->toDateString(),
            'period_end' => $entitlement->period_end->toDateString(),
            'status' => $entitlement->status,
            'balance' => $this->balanceService->balance($tenantId, $entitlement->id),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $balances,
        ]);
    }

    /**
     * §15.3 — GET /employments/{employmentId}/leave-entitlements.
     */
    public function employmentEntitlements(Request $request, string $employmentId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $entitlements = LeaveEntitlement::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('period_start')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $entitlements,
        ]);
    }

    /**
     * §15.3 — POST /employments/{employmentId}/leave-entitlements/generate.
     */
    public function generate(GenerateLeaveEntitlementRequest $request, string $employmentId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array{leave_type_id: string, period_start: string, period_end: string} $payload */
        $payload = $request->validated();

        try {
            $entitlement = $this->entitlementService->generateForPeriod(
                $tenantId,
                $employmentId,
                $payload['leave_type_id'],
                $payload['period_start'],
                $payload['period_end'],
            );
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'LEAVE_EMPLOYMENT_OR_TYPE_NOT_FOUND',
                message: 'Referenced Employment or LeaveType was not found in the current tenant.',
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveEntitlement generation failed.',
                [
                    'tenant_id' => $tenantId,
                    'employment_id' => $employmentId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_ENTITLEMENT_GENERATION_FAILED',
                message: 'Failed to generate LeaveEntitlement record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $entitlement,
        ], 201);
    }

    /**
     * §15.3 — POST /leave-entitlements/{id}/adjustments. Client TIDAK
     * PERNAH mengirim `actor_membership_id` — diambil dari Membership
     * terautentikasi, bukan input.
     */
    public function adjust(AdjustLeaveEntitlementRequest $request, string $entitlementId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array{units_delta: float|string, reason: string|null, idempotency_key: string} $payload */
        $payload = $request->validated();

        try {
            $this->balanceService->adjust(
                $tenantId,
                $entitlementId,
                (string) $payload['units_delta'],
                $payload['idempotency_key'],
                $membershipId,
                $payload['reason'] ?? null,
            );
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'LEAVE_ENTITLEMENT_NOT_FOUND',
                message: sprintf('LeaveEntitlement [%s] was not found in the current tenant.', $entitlementId),
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (LeaveLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'LeaveEntitlement adjustment failed.',
                [
                    'tenant_id' => $tenantId,
                    'entitlement_id' => $entitlementId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'LEAVE_ENTITLEMENT_ADJUSTMENT_FAILED',
                message: 'Failed to adjust LeaveEntitlement balance.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'entitlement_id' => $entitlementId,
                'balance' => $this->balanceService->balance($tenantId, $entitlementId),
            ],
        ], 201);
    }

    private function conflictResponse(LeaveLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'LEAVE_ENTITLEMENT_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
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
