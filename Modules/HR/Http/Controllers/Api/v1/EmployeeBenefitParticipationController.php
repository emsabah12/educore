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
use Modules\HR\Exceptions\BenefitParticipationLifecycleException;
use Modules\HR\Http\Requests\StoreBenefitParticipationRequest;
use Modules\HR\Models\EmployeeBenefitParticipation;
use Modules\HR\Services\BenefitParticipationService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-006 §7.6 — Employee Benefit Participation lifecycle HTTP layer.
 * Pola identik CompensationAssignmentController — tenant-wide saja
 * (tanpa audit trail/workspace scoping) untuk rilis pertama ini.
 */
final class EmployeeBenefitParticipationController extends Controller
{
    public function __construct(
        private readonly BenefitParticipationService $service,
    ) {}

    public function index(Request $request, string $employmentId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $participations = EmployeeBenefitParticipation::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $participations->map(
                fn(EmployeeBenefitParticipation $participation): array => $this->serialize($participation),
            ),
        ]);
    }

    public function store(
        StoreBenefitParticipationRequest $request,
        string $employmentId,
    ): JsonResponse {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     benefit_program_id: string,
         *     beneficiary_person_id?: string|null,
         *     effective_from: string,
         *     effective_to?: string|null,
         *     notes?: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $participation = $this->service->create(
                tenantId: $tenantId,
                employmentId: $employmentId,
                data: $payload,
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf(
                    'Employment [%s] or the referenced BenefitProgram was not found in the current tenant.',
                    $employmentId,
                ),
            );
        } catch (BenefitParticipationLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            return $this->operationFailedResponse($tenantId, $employmentId, $exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($participation),
        ], 201);
    }

    public function enroll(Request $request, string $employmentId, string $participationId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $participation = $this->service->enroll(
                tenantId: $tenantId,
                employmentId: $employmentId,
                participationId: $participationId,
                verifierMembershipId: $membershipId,
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf(
                    'EmployeeBenefitParticipation referenced under Employment [%s] was not found in the current tenant.',
                    $employmentId,
                ),
            );
        } catch (BenefitParticipationLifecycleException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            return $this->operationFailedResponse($tenantId, $employmentId, $exception);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($participation),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EmployeeBenefitParticipation $participation): array
    {
        return [
            'id' => (string) $participation->id,
            'employment_id' => (string) $participation->employment_id,
            'benefit_program_id' => (string) $participation->benefit_program_id,
            'beneficiary_person_id' => $participation->beneficiary_person_id,
            'status' => $participation->status,
            'effective_from' => $participation->effective_from?->toDateString(),
            'effective_to' => $participation->effective_to?->toDateString(),
            'verified_at' => $participation->verified_at?->toJSON(),
            'verified_by_membership_id' => $participation->verified_by_membership_id,
            'notes' => $participation->notes,
        ];
    }

    private function operationFailedResponse(
        string $tenantId,
        string $employmentId,
        Throwable $exception,
    ): JsonResponse {
        Log::error(
            'EmployeeBenefitParticipation operation failed.',
            [
                'tenant_id' => $tenantId,
                'employment_id' => $employmentId,
                'exception_class' => $exception::class,
            ],
        );

        return ApiErrorResponse::make(
            code: 'BENEFIT_PARTICIPATION_OPERATION_FAILED',
            message: 'Failed to process EmployeeBenefitParticipation operation.',
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    private function conflictResponse(BenefitParticipationLifecycleException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BENEFIT_PARTICIPATION_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BENEFIT_PARTICIPATION_NOT_FOUND',
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
