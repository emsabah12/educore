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
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Http\Requests\StoreRecruitmentApplicationRequest;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Services\RecruitmentApplicationLifecycleService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecruitmentApplicationController extends Controller
{
    public function __construct(
        private readonly RecruitmentApplicationLifecycleService $applicationLifecycleService,
    ) {}

    public function index(Request $request, string $vacancyId): JsonResponse
    {
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

        $applications = RecruitmentApplication::query()
            ->where('vacancy_id', $vacancyId)
            ->orderByDesc('submitted_at')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $applications->items(),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    public function store(
        StoreRecruitmentApplicationRequest $request,
        string $vacancyId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array{candidate_id: string} $payload */
        $payload = $request->validated();

        try {
            $application = $this->applicationLifecycleService->submitApplication(
                tenantId: $tenantId,
                vacancyId: $vacancyId,
                candidateId: $payload['candidate_id'],
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf('Vacancy [%s] was not found in the current tenant.', $vacancyId),
            );
        } catch (RecruitmentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentApplication submission failed.',
                [
                    'tenant_id' => $tenantId,
                    'vacancy_id' => $vacancyId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_APPLICATION_SUBMISSION_FAILED',
                message: 'Failed to persist RecruitmentApplication record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Application submitted with SUBMITTED status.',
            'data' => $application,
        ], 201);
    }

    public function startProcessing(Request $request, string $applicationId): JsonResponse
    {
        return $this->transition(
            $request,
            $applicationId,
            fn(string $tenantId): RecruitmentApplication => $this->applicationLifecycleService
                ->startProcessing($tenantId, $applicationId),
        );
    }

    public function reject(Request $request, string $applicationId): JsonResponse
    {
        return $this->transitionWithDecision(
            $request,
            $applicationId,
            fn(string $tenantId, string $membershipId, ?string $reason): RecruitmentApplication => $this->applicationLifecycleService
                ->reject($tenantId, $applicationId, $membershipId, $reason),
        );
    }

    public function withdraw(Request $request, string $applicationId): JsonResponse
    {
        return $this->transition(
            $request,
            $applicationId,
            fn(string $tenantId): RecruitmentApplication => $this->applicationLifecycleService
                ->withdraw($tenantId, $applicationId),
        );
    }

    public function approveForHiring(Request $request, string $applicationId): JsonResponse
    {
        return $this->transitionWithDecision(
            $request,
            $applicationId,
            fn(string $tenantId, string $membershipId, ?string $reason): RecruitmentApplication => $this->applicationLifecycleService
                ->approveForHiring($tenantId, $applicationId, $membershipId, $reason),
        );
    }

    /**
     * @param callable(string): RecruitmentApplication $operation
     */
    private function transition(
        Request $request,
        string $applicationId,
        callable $operation,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $application = $operation($tenantId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf('Application [%s] was not found in the current tenant.', $applicationId),
            );
        } catch (RecruitmentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentApplication transition failed.',
                [
                    'tenant_id' => $tenantId,
                    'application_id' => $applicationId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_APPLICATION_TRANSITION_FAILED',
                message: 'Failed to transition Application lifecycle state.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $application,
        ]);
    }

    /**
     * @param callable(string, string, ?string): RecruitmentApplication $operation
     */
    private function transitionWithDecision(
        Request $request,
        string $applicationId,
        callable $operation,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $membershipId = $request->attributes->get(
            'authenticated_membership_id',
        );

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? $reason : null;

        try {
            $application = $operation($tenantId, $membershipId, $reason);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf('Application [%s] was not found in the current tenant.', $applicationId),
            );
        } catch (RecruitmentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentApplication decision failed.',
                [
                    'tenant_id' => $tenantId,
                    'application_id' => $applicationId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_APPLICATION_DECISION_FAILED',
                message: 'Failed to record Application hiring decision.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $application,
        ]);
    }

    private function lifecycleConflictResponse(
        RecruitmentLifecycleException $exception,
    ): JsonResponse {
        return ApiErrorResponse::make(
            code: 'RECRUITMENT_APPLICATION_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'RECRUITMENT_APPLICATION_NOT_FOUND',
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
