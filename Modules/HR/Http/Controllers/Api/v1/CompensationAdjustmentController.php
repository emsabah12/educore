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
use Modules\HR\Exceptions\CompensationAdjustmentLifecycleException;
use Modules\HR\Http\Requests\StoreCompensationAdjustmentRequest;
use Modules\HR\Models\CompensationAdjustment;
use Modules\HR\Services\CompensationAdjustmentService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-006 §7.8 — Compensation Adjustment lifecycle HTTP layer.
 * Pola identik CompensationAssignmentController — tenant-wide saja
 * (tanpa audit trail/workspace scoping) untuk rilis pertama ini.
 */
final class CompensationAdjustmentController extends Controller
{
    public function __construct(
        private readonly CompensationAdjustmentService $service,
    ) {}

    public function index(Request $request, string $employmentId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $adjustments = CompensationAdjustment::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $adjustments->map(
                fn (CompensationAdjustment $adjustment): array => $this->serialize($adjustment),
            ),
        ]);
    }

    public function store(
        StoreCompensationAdjustmentRequest $request,
        string $employmentId,
    ): JsonResponse {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $requesterMembershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($requesterMembershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     compensation_component_id?: string|null,
         *     adjustment_type: string,
         *     amount: string,
         *     currency_code: string,
         *     target_period_start: string,
         *     target_period_end: string,
         *     reason: string,
         *     idempotency_key: string,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $adjustment = $this->service->create(
                tenantId: $tenantId,
                employmentId: $employmentId,
                requesterMembershipId: $requesterMembershipId,
                data: $payload,
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf(
                    'Employment [%s] or the referenced CompensationComponent was not found in the current tenant.',
                    $employmentId,
                ),
            );
        } catch (CompensationAdjustmentLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            return $this->operationFailedResponse($tenantId, $employmentId, $exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($adjustment),
        ], 201);
    }

    public function submit(Request $request, string $employmentId, string $adjustmentId): JsonResponse
    {
        return $this->transition(
            $request,
            $employmentId,
            fn (string $tenantId): CompensationAdjustment => $this->service->submit(
                tenantId: $tenantId,
                employmentId: $employmentId,
                adjustmentId: $adjustmentId,
            ),
        );
    }

    public function approve(Request $request, string $employmentId, string $adjustmentId): JsonResponse
    {
        return $this->transition(
            $request,
            $employmentId,
            fn (string $tenantId, string $actorMembershipId): CompensationAdjustment => $this->service->approve(
                tenantId: $tenantId,
                employmentId: $employmentId,
                adjustmentId: $adjustmentId,
                approverMembershipId: $actorMembershipId,
            ),
        );
    }

    public function reject(Request $request, string $employmentId, string $adjustmentId): JsonResponse
    {
        return $this->transition(
            $request,
            $employmentId,
            fn (string $tenantId, string $actorMembershipId): CompensationAdjustment => $this->service->reject(
                tenantId: $tenantId,
                employmentId: $employmentId,
                adjustmentId: $adjustmentId,
                reviewerMembershipId: $actorMembershipId,
            ),
        );
    }

    public function cancel(Request $request, string $employmentId, string $adjustmentId): JsonResponse
    {
        return $this->transition(
            $request,
            $employmentId,
            fn (string $tenantId): CompensationAdjustment => $this->service->cancel(
                tenantId: $tenantId,
                employmentId: $employmentId,
                adjustmentId: $adjustmentId,
            ),
        );
    }

    /**
     * @param  callable(string, string): CompensationAdjustment  $operation
     *                                                                       Menerima (tenantId, actorMembershipId) — parameter kedua
     *                                                                       hanya relevan untuk approve()/reject(), disediakan seragam
     *                                                                       supaya satu helper ini dipakai kelima aksi transisi.
     */
    private function transition(
        Request $request,
        string $employmentId,
        callable $operation,
    ): JsonResponse {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $adjustment = $operation($tenantId, $membershipId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf(
                    'CompensationAdjustment referenced under Employment [%s] was not found in the current tenant.',
                    $employmentId,
                ),
            );
        } catch (CompensationAdjustmentLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            return $this->operationFailedResponse($tenantId, $employmentId, $exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($adjustment),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CompensationAdjustment $adjustment): array
    {
        return [
            'id' => (string) $adjustment->id,
            'employment_id' => (string) $adjustment->employment_id,
            'compensation_component_id' => $adjustment->compensation_component_id,
            'adjustment_type' => $adjustment->adjustment_type,
            'amount' => $adjustment->amount,
            'currency_code' => $adjustment->currency_code,
            'target_period_start' => $adjustment->target_period_start?->toDateString(),
            'target_period_end' => $adjustment->target_period_end?->toDateString(),
            'status' => $adjustment->status,
            'reason' => $adjustment->reason,
            'requested_by_membership_id' => $adjustment->requested_by_membership_id,
            'approved_by_membership_id' => $adjustment->approved_by_membership_id,
            'approved_at' => $adjustment->approved_at?->toJSON(),
            'idempotency_key' => $adjustment->idempotency_key,
        ];
    }

    private function operationFailedResponse(
        string $tenantId,
        string $employmentId,
        Throwable $exception,
    ): JsonResponse {
        Log::error(
            'CompensationAdjustment operation failed.',
            [
                'tenant_id' => $tenantId,
                'employment_id' => $employmentId,
                'exception_class' => $exception::class,
            ],
        );

        return ApiErrorResponse::make(
            code: 'COMPENSATION_ADJUSTMENT_OPERATION_FAILED',
            message: 'Failed to process CompensationAdjustment operation.',
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    private function conflictResponse(CompensationAdjustmentLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'COMPENSATION_ADJUSTMENT_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'COMPENSATION_ADJUSTMENT_NOT_FOUND',
            message: $message,
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
