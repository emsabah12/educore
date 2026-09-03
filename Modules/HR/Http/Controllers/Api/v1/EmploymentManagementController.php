<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Http\Controllers\Concerns\ChecksHrResourceScope;
use Modules\HR\Http\Requests\EndEmploymentRequest;
use Modules\HR\Http\Requests\StoreEmploymentRequest;
use Modules\HR\Models\Employment;
use Modules\HR\Services\EmploymentLifecycleService;
use Modules\HR\Services\HrWorkforceScopeService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EmploymentManagementController extends Controller
{
    use ChecksHrResourceScope;

    public function __construct(
        private readonly EmploymentLifecycleService $employmentLifecycleService,
        private readonly AuditTrailServiceInterface $auditTrail,
        private readonly HrWorkforceScopeService $hrWorkforceScopeService,
        private readonly OrganizationalContextInterface $organizationalContext,
    ) {}

    public function index(
        Request $request,
        string $employeeId,
    ): JsonResponse {
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

        // Employment sudah otomatis tersaring ke tenant aktif lewat
        // BelongsToTenant global scope (tenant context sudah diset oleh
        // InjectTenantContext middleware sebelum controller ini jalan),
        // jadi cukup filter employee_id di sini.
        $employments = Employment::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $employments->items(),
            'meta' => [
                'current_page' => $employments->currentPage(),
                'last_page' => $employments->lastPage(),
                'per_page' => $employments->perPage(),
                'total' => $employments->total(),
            ],
        ]);
    }

    public function store(
        StoreEmploymentRequest $request,
        string $employeeId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $operatorId = $this->isCanonicalUuid($operatorId)
            ? $operatorId
            : null;

        $scopeDeniedResponse = $this->scopeDeniedResponseIfEmployeeNotVisible(
            $tenantId,
            $employeeId,
        );

        if ($scopeDeniedResponse !== null) {
            return $scopeDeniedResponse;
        }

        /**
         * @var array{
         *     employment_type_id: string|null,
         *     employment_classification_id: string|null,
         *     start_date: string,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $employment = $this->employmentLifecycleService->createPlanned(
                tenantId: $tenantId,
                employeeId: $employeeId,
                data: $payload,
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse(
                'EMPLOYEE_NOT_FOUND',
                sprintf(
                    'Employee [%s] was not found in the current tenant.',
                    $employeeId,
                ),
            );
        } catch (EmploymentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'Employment creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'operator_user_id' => $operatorId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_CREATION_FAILED',
                message: 'Failed to persist Employment record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $this->auditSafely(
            eventType: 'employment.created',
            description: 'Created Employment with PLANNED status.',
            tenantId: $tenantId,
            operatorId: $operatorId,
            metadata: [
                'employment_id' => $employment->id,
                'employee_id' => $employment->employee_id,
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Employment created with PLANNED status.',
            'data' => $employment,
        ], 201);
    }

    public function activate(
        Request $request,
        string $employmentId,
    ): JsonResponse {
        return $this->transition(
            request: $request,
            employmentId: $employmentId,
            operation: fn(string $tenantId): Employment => $this->employmentLifecycleService
                ->activate($tenantId, $employmentId),
            auditEventType: 'employment.activated',
            auditDescription: 'Activated Employment.',
        );
    }

    public function cancel(
        Request $request,
        string $employmentId,
    ): JsonResponse {
        return $this->transition(
            request: $request,
            employmentId: $employmentId,
            operation: fn(string $tenantId): Employment => $this->employmentLifecycleService
                ->cancel($tenantId, $employmentId),
            auditEventType: 'employment.cancelled',
            auditDescription: 'Cancelled Employment.',
        );
    }

    /**
     * HR-002 §9.4 — End Employment. Permission-nya sengaja terpisah
     * (`hr.employments.end`, lihat routes) karena ini higher-impact
     * operation dibanding create/activate/cancel.
     */
    public function end(
        EndEmploymentRequest $request,
        string $employmentId,
    ): JsonResponse {
        /** @var array{end_date: string} $payload */
        $payload = $request->validated();

        return $this->transition(
            request: $request,
            employmentId: $employmentId,
            operation: fn(string $tenantId): Employment => $this->employmentLifecycleService
                ->end($tenantId, $employmentId, $payload['end_date']),
            auditEventType: 'employment.ended',
            auditDescription: 'Ended Employment.',
        );
    }

    /**
     * @param callable(string): Employment $operation
     */
    private function transition(
        Request $request,
        string $employmentId,
        callable $operation,
        string $auditEventType,
        string $auditDescription,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $operatorId = $this->isCanonicalUuid($operatorId)
            ? $operatorId
            : null;

        $employeeId = DB::table('employments')
            ->where('id', $employmentId)
            ->where('tenant_id', $tenantId)
            ->value('employee_id');

        if ($employeeId !== null) {
            $scopeDeniedResponse = $this->scopeDeniedResponseIfEmployeeNotVisible(
                $tenantId,
                $employeeId,
            );

            if ($scopeDeniedResponse !== null) {
                return $scopeDeniedResponse;
            }
        }

        try {
            $employment = $operation($tenantId);
        } catch (ModelNotFoundException) {
            // Catatan: ModelNotFoundException di sini bisa juga berarti
            // Employee pemilik Employment sudah soft-deleted (bukan cuma
            // Employment-nya sendiri tidak ditemukan). Kasus itu jarang
            // terjadi dan akan diperjelas kodenya saat modul Offboarding
            // dibangun; untuk sekarang keduanya dilaporkan sebagai
            // "Employment not found" demi kesederhanaan pesan ke client.
            return $this->notFoundResponse(
                'EMPLOYMENT_NOT_FOUND',
                sprintf(
                    'Employment [%s] was not found in the current tenant.',
                    $employmentId,
                ),
            );
        } catch (EmploymentLifecycleException $exception) {
            return $this->lifecycleConflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'Employment lifecycle transition failed.',
                [
                    'tenant_id' => $tenantId,
                    'employment_id' => $employmentId,
                    'operator_user_id' => $operatorId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_TRANSITION_FAILED',
                message: 'Failed to transition Employment lifecycle state.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $this->auditSafely(
            eventType: $auditEventType,
            description: $auditDescription,
            tenantId: $tenantId,
            operatorId: $operatorId,
            metadata: [
                'employment_id' => $employment->id,
                'employee_id' => $employment->employee_id,
                'status' => $employment->status,
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => $auditDescription,
            'data' => $employment,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function auditSafely(
        string $eventType,
        string $description,
        string $tenantId,
        ?string $operatorId,
        array $metadata,
    ): void {
        try {
            $this->auditTrail->log(
                eventType: $eventType,
                description: $description,
                tenantId: $tenantId,
                actorUserId: $operatorId,
                metadata: $metadata,
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }
    }

    private function lifecycleConflictResponse(
        EmploymentLifecycleException $exception,
    ): JsonResponse {
        return ApiErrorResponse::make(
            code: 'EMPLOYMENT_LIFECYCLE_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(
        string $code,
        string $message,
    ): JsonResponse {
        return ApiErrorResponse::make(
            code: $code,
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
