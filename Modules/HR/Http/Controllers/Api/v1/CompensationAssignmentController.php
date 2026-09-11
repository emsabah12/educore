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
use Modules\HR\Exceptions\CompensationLifecycleException;
use Modules\HR\Http\Requests\EndCompensationAssignmentRequest;
use Modules\HR\Http\Requests\StoreCompensationAssignmentRequest;
use Modules\HR\Models\CompensationAssignment;
use Modules\HR\Services\CompensationAssignmentService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-006 §7.3 — Compensation Assignment lifecycle HTTP layer.
 *
 * SENGAJA tenant-wide saja untuk rilis pertama ini (tanpa audit trail
 * dan tanpa workspace-scoped organizational variant seperti
 * `EmploymentManagementController`) — mengikuti tingkat kesederhanaan
 * yang sama dengan grup route "Leave & Permit Admin Configuration"
 * (`LeaveApprovalController`), bukan grup workspace Employment yang
 * lebih kompleks. Audit trail + workspace scoping bisa ditambah
 * sebagai step terpisah kalau memang dibutuhkan nyata.
 */
final class CompensationAssignmentController extends Controller
{
    public function __construct(
        private readonly CompensationAssignmentService $service,
    ) {}

    public function index(Request $request, string $employmentId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $assignments = CompensationAssignment::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $assignments->map(
                fn(CompensationAssignment $assignment): array => $this->serialize($assignment),
            ),
        ]);
    }

    public function store(
        StoreCompensationAssignmentRequest $request,
        string $employmentId,
    ): JsonResponse {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     compensation_component_id: string,
         *     employment_position_assignment_id?: string|null,
         *     amount?: string|null,
         *     rate?: string|null,
         *     currency_code: string,
         *     effective_from: string,
         *     effective_to?: string|null,
         *     reason?: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $assignment = $this->service->createDraft(
                tenantId: $tenantId,
                employmentId: $employmentId,
                data: $payload,
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf(
                    'Employment [%s] or the referenced CompensationComponent/EmploymentPositionAssignment was not found in the current tenant.',
                    $employmentId,
                ),
            );
        } catch (CompensationLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            return $this->creationFailedResponse($tenantId, $employmentId, $exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($assignment),
        ], 201);
    }

    public function approve(Request $request, string $employmentId, string $assignmentId): JsonResponse
    {
        return $this->transition(
            $request,
            $employmentId,
            fn(string $tenantId, string $approverMembershipId): CompensationAssignment => $this->service->approve(
                tenantId: $tenantId,
                employmentId: $employmentId,
                assignmentId: $assignmentId,
                approverMembershipId: $approverMembershipId,
            ),
        );
    }

    public function end(
        EndCompensationAssignmentRequest $request,
        string $employmentId,
        string $assignmentId,
    ): JsonResponse {
        /** @var array{end_date: string} $payload */
        $payload = $request->validated();

        return $this->transition(
            $request,
            $employmentId,
            fn(string $tenantId): CompensationAssignment => $this->service->end(
                tenantId: $tenantId,
                employmentId: $employmentId,
                assignmentId: $assignmentId,
                endDate: $payload['end_date'],
            ),
        );
    }

    public function correct(
        StoreCompensationAssignmentRequest $request,
        string $employmentId,
        string $assignmentId,
    ): JsonResponse {
        /**
         * @var array{
         *     compensation_component_id: string,
         *     employment_position_assignment_id?: string|null,
         *     amount?: string|null,
         *     rate?: string|null,
         *     currency_code: string,
         *     effective_from: string,
         *     effective_to?: string|null,
         *     reason?: string|null,
         * } $payload
         */
        $payload = $request->validated();

        return $this->transition(
            $request,
            $employmentId,
            fn(string $tenantId): CompensationAssignment => $this->service->correct(
                tenantId: $tenantId,
                employmentId: $employmentId,
                originalAssignmentId: $assignmentId,
                data: $payload,
            ),
        );
    }

    /**
     * @param callable(string, string): CompensationAssignment $operation
     *     Menerima (tenantId, approverMembershipId) — parameter kedua
     *     HANYA relevan untuk `approve()`, tapi disediakan seragam
     *     supaya satu helper ini bisa dipakai ketiga aksi transisi.
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
            $assignment = $operation($tenantId, $membershipId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf(
                    'CompensationAssignment referenced under Employment [%s] was not found in the current tenant.',
                    $employmentId,
                ),
            );
        } catch (CompensationLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            return $this->creationFailedResponse($tenantId, $employmentId, $exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($assignment),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CompensationAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->id,
            'employment_id' => (string) $assignment->employment_id,
            'compensation_component_id' => (string) $assignment->compensation_component_id,
            'employment_position_assignment_id' => $assignment->employment_position_assignment_id,
            'status' => $assignment->status,
            'amount' => $assignment->amount,
            'rate' => $assignment->rate,
            'currency_code' => $assignment->currency_code,
            'effective_from' => $assignment->effective_from?->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'supersedes_assignment_id' => $assignment->supersedes_assignment_id,
            'approved_by_membership_id' => $assignment->approved_by_membership_id,
            'approved_at' => $assignment->approved_at?->toJSON(),
            'ended_at' => $assignment->ended_at?->toJSON(),
            'reason' => $assignment->reason,
        ];
    }

    private function creationFailedResponse(
        string $tenantId,
        string $employmentId,
        Throwable $exception,
    ): JsonResponse {
        Log::error(
            'CompensationAssignment operation failed.',
            [
                'tenant_id' => $tenantId,
                'employment_id' => $employmentId,
                'exception_class' => $exception::class,
            ],
        );

        return ApiErrorResponse::make(
            code: 'COMPENSATION_ASSIGNMENT_OPERATION_FAILED',
            message: 'Failed to process CompensationAssignment operation.',
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    private function conflictResponse(CompensationLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'COMPENSATION_ASSIGNMENT_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'COMPENSATION_ASSIGNMENT_NOT_FOUND',
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
