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
use Modules\HR\Http\Requests\DecideRecruitmentVacancyRequest;
use Modules\HR\Http\Requests\StoreRecruitmentVacancyRequest;
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Services\RecruitmentVacancyLifecycleService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecruitmentVacancyController extends Controller
{
    public function __construct(
        private readonly RecruitmentVacancyLifecycleService $vacancyLifecycleService,
    ) {}

    public function index(Request $request): JsonResponse
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

        $vacancies = RecruitmentVacancy::query()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $vacancies->items(),
            'meta' => [
                'current_page' => $vacancies->currentPage(),
                'last_page' => $vacancies->lastPage(),
                'per_page' => $vacancies->perPage(),
                'total' => $vacancies->total(),
            ],
        ]);
    }

    public function store(StoreRecruitmentVacancyRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $membershipId = $request->attributes->get(
            'authenticated_membership_id',
        );

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     code: string,
         *     title: string,
         *     position_id: string,
         *     organization_id: string,
         *     organization_unit_id: string|null,
         *     requested_headcount: int,
         *     description: string|null,
         * } $payload
         */
        $payload = $request->validated();
        $payload['created_by_membership_id'] = $membershipId;

        try {
            $vacancy = $this->vacancyLifecycleService->createDraft($tenantId, $payload);
        } catch (RecruitmentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentVacancy creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_VACANCY_CREATION_FAILED',
                message: 'Failed to persist RecruitmentVacancy record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Vacancy created with DRAFT status.',
            'data' => $vacancy,
        ], 201);
    }

    public function submit(Request $request, string $vacancyId): JsonResponse
    {
        return $this->transition(
            request: $request,
            vacancyId: $vacancyId,
            operation: fn(string $tenantId): RecruitmentVacancy => $this->vacancyLifecycleService
                ->submit($tenantId, $vacancyId),
        );
    }

    public function approve(DecideRecruitmentVacancyRequest $request, string $vacancyId): JsonResponse
    {
        /** @var array{reason: string|null} $payload */
        $payload = $request->validated();

        return $this->transitionWithDecision(
            $request,
            $vacancyId,
            fn(string $tenantId, string $membershipId): RecruitmentVacancy => $this->vacancyLifecycleService
                ->approve($tenantId, $vacancyId, $membershipId, $payload['reason'] ?? null),
        );
    }

    public function reject(DecideRecruitmentVacancyRequest $request, string $vacancyId): JsonResponse
    {
        /** @var array{reason: string|null} $payload */
        $payload = $request->validated();

        return $this->transitionWithDecision(
            $request,
            $vacancyId,
            fn(string $tenantId, string $membershipId): RecruitmentVacancy => $this->vacancyLifecycleService
                ->reject($tenantId, $vacancyId, $membershipId, $payload['reason'] ?? null),
        );
    }

    public function open(Request $request, string $vacancyId): JsonResponse
    {
        return $this->transition(
            request: $request,
            vacancyId: $vacancyId,
            operation: fn(string $tenantId): RecruitmentVacancy => $this->vacancyLifecycleService
                ->open($tenantId, $vacancyId),
        );
    }

    public function close(Request $request, string $vacancyId): JsonResponse
    {
        return $this->transition(
            request: $request,
            vacancyId: $vacancyId,
            operation: fn(string $tenantId): RecruitmentVacancy => $this->vacancyLifecycleService
                ->close($tenantId, $vacancyId),
        );
    }

    public function cancel(Request $request, string $vacancyId): JsonResponse
    {
        return $this->transition(
            request: $request,
            vacancyId: $vacancyId,
            operation: fn(string $tenantId): RecruitmentVacancy => $this->vacancyLifecycleService
                ->cancel($tenantId, $vacancyId),
        );
    }

    /**
     * @param callable(string): RecruitmentVacancy $operation
     */
    private function transition(
        Request $request,
        string $vacancyId,
        callable $operation,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $vacancy = $operation($tenantId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($vacancyId);
        } catch (RecruitmentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentVacancy transition failed.',
                [
                    'tenant_id' => $tenantId,
                    'vacancy_id' => $vacancyId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_VACANCY_TRANSITION_FAILED',
                message: 'Failed to transition Vacancy lifecycle state.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $vacancy,
        ]);
    }

    /**
     * @param callable(string, string): RecruitmentVacancy $operation
     */
    private function transitionWithDecision(
        Request $request,
        string $vacancyId,
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

        try {
            $vacancy = $operation($tenantId, $membershipId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse($vacancyId);
        } catch (RecruitmentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentVacancy decision failed.',
                [
                    'tenant_id' => $tenantId,
                    'vacancy_id' => $vacancyId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_VACANCY_DECISION_FAILED',
                message: 'Failed to record Vacancy decision.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $vacancy,
        ]);
    }

    private function lifecycleConflictResponse(
        RecruitmentLifecycleException $exception,
    ): JsonResponse {
        return ApiErrorResponse::make(
            code: 'RECRUITMENT_VACANCY_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $vacancyId): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'RECRUITMENT_VACANCY_NOT_FOUND',
            message: sprintf(
                'Vacancy [%s] was not found in the current tenant.',
                $vacancyId,
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
