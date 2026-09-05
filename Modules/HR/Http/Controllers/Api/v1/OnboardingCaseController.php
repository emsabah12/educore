<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Exceptions\OnboardingLifecycleException;
use Modules\HR\Http\Requests\CancelOnboardingCaseRequest;
use Modules\HR\Http\Requests\FinalizeOnboardingTaskRequest;
use Modules\HR\Http\Requests\StoreOnboardingCaseRequest;
use Modules\HR\Models\OnboardingCase;
use Modules\HR\Models\OnboardingTask;
use Modules\HR\Services\OnboardingCaseLifecycleService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class OnboardingCaseController extends Controller
{
    public function __construct(
        private readonly OnboardingCaseLifecycleService $onboardingCaseLifecycleService,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    public function store(
        StoreOnboardingCaseRequest $request,
        string $applicationId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array{template_id: string|null} $payload */
        $payload = $request->validated();

        try {
            $case = $this->onboardingCaseLifecycleService->createCase(
                tenantId: $tenantId,
                applicationId: $applicationId,
                templateId: $payload['template_id'] ?? null,
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf('Application [%s] was not found in the current tenant.', $applicationId),
            );
        } catch (OnboardingLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'OnboardingCase creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'application_id' => $applicationId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'ONBOARDING_CASE_CREATION_FAILED',
                message: 'Failed to persist OnboardingCase record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Onboarding Case created.',
            'data' => $case->load('tasks'),
        ], 201);
    }

    public function start(Request $request, string $caseId): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        try {
            $case = $this->onboardingCaseLifecycleService->startProgress($tenantId, $caseId);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf('Onboarding Case [%s] was not found in the current tenant.', $caseId),
            );
        } catch (OnboardingLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'OnboardingCase start failed.',
                [
                    'tenant_id' => $tenantId,
                    'case_id' => $caseId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'ONBOARDING_CASE_START_FAILED',
                message: 'Failed to start Onboarding Case.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $case,
        ]);
    }

    public function cancel(CancelOnboardingCaseRequest $request, string $caseId): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /** @var array{reason: string} $payload */
        $payload = $request->validated();

        try {
            $case = $this->onboardingCaseLifecycleService->cancel(
                $tenantId,
                $caseId,
                $payload['reason'],
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf('Onboarding Case [%s] was not found in the current tenant.', $caseId),
            );
        } catch (OnboardingLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'OnboardingCase cancellation failed.',
                [
                    'tenant_id' => $tenantId,
                    'case_id' => $caseId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'ONBOARDING_CASE_CANCELLATION_FAILED',
                message: 'Failed to cancel Onboarding Case.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // "CANCELLED is allowed before completion with explicit
        // authorized reason" (§8.3) — reason TIDAK punya kolom
        // penyimpanan di onboarding_cases (§7.12), jadi bukti "explicit
        // authorized reason" disimpan lewat Audit Trail di sini.
        try {
            $this->auditTrail->log(
                eventType: 'hr.onboarding.case.cancelled',
                description: 'Cancelled Onboarding Case.',
                tenantId: $tenantId,
                actorUserId: $this->isCanonicalUuid($operatorId) ? $operatorId : null,
                metadata: [
                    'onboarding_case_id' => $case->id,
                    'reason' => $payload['reason'],
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }

        return response()->json([
            'status' => 'success',
            'data' => $case,
        ]);
    }

    public function completeTask(FinalizeOnboardingTaskRequest $request, string $taskId): JsonResponse
    {
        return $this->finalizeTask(
            $request,
            $taskId,
            fn(string $tenantId, string $membershipId, ?string $note): OnboardingTask => $this->onboardingCaseLifecycleService
                ->completeTask($tenantId, $taskId, $membershipId, $note),
        );
    }

    public function waiveTask(FinalizeOnboardingTaskRequest $request, string $taskId): JsonResponse
    {
        return $this->finalizeTask(
            $request,
            $taskId,
            fn(string $tenantId, string $membershipId, ?string $note): OnboardingTask => $this->onboardingCaseLifecycleService
                ->waiveTask($tenantId, $taskId, $membershipId, $note),
        );
    }

    /**
     * @param callable(string, string, ?string): OnboardingTask $operation
     */
    private function finalizeTask(
        Request $request,
        string $taskId,
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

        /** @var array{note: string|null} $payload */
        $payload = $request->validated();

        try {
            $task = $operation($tenantId, $membershipId, $payload['note'] ?? null);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                sprintf('Onboarding Task [%s] was not found in the current tenant.', $taskId),
            );
        } catch (OnboardingLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'OnboardingTask finalization failed.',
                [
                    'tenant_id' => $tenantId,
                    'task_id' => $taskId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'ONBOARDING_TASK_FINALIZATION_FAILED',
                message: 'Failed to finalize Onboarding Task.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $task,
        ]);
    }

    private function lifecycleConflictResponse(
        OnboardingLifecycleException $exception,
    ): JsonResponse {
        return ApiErrorResponse::make(
            code: 'ONBOARDING_CASE_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'ONBOARDING_CASE_NOT_FOUND',
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
