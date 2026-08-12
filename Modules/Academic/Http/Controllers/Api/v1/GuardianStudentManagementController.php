<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Academic\Contracts\GuardianStudentRepositoryInterface;
use Modules\Academic\Http\Requests\DeleteGuardianStudentAssociationRequest;
use Modules\Academic\Http\Requests\StoreGuardianStudentAssociationRequest;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Throwable;

final class GuardianStudentManagementController extends Controller
{
    public function __construct(
        private readonly GuardianStudentRepositoryInterface $guardianStudentRepository,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    public function index(
        Request $request,
        string $guardianId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        if (! UuidV7::validate($guardianId)) {
            return $this->notFoundResponse();
        }

        try {
            $students = $this->guardianStudentRepository
                ->getStudentsByGuardian(
                    $tenantId,
                    $guardianId,
                );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse();
        } catch (Throwable $exception) {
            $this->logUnexpectedFailure(
                operation: 'list',
                tenantId: $tenantId,
                operatorId: $request->attributes->get(
                    'authenticated_user_id',
                ),
                exception: $exception,
            );

            return $this->internalErrorResponse();
        }

        return response()->json([
            'status' => 'success',
            'data' => $students,
        ]);
    }

    public function store(
        StoreGuardianStudentAssociationRequest $request,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        /** @var array{guardian_id:string,student_id:string,relationship_type:string} $payload */
        $payload = $request->validated();

        try {
            $created = $this->guardianStudentRepository
                ->attachStudentToGuardian(
                    tenantId: $tenantId,
                    guardianId: $payload['guardian_id'],
                    studentId: $payload['student_id'],
                    relationshipType: $payload['relationship_type'],
                );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse();
        } catch (Throwable $exception) {
            $this->logUnexpectedFailure(
                operation: 'attach',
                tenantId: $tenantId,
                operatorId: $operatorId,
                exception: $exception,
            );

            return $this->internalErrorResponse();
        }

        if ($created) {
            $this->auditTrail->log(
                eventType: 'guardian_student.attached',
                description: 'Attached student profile to guardian profile.',
                tenantId: $tenantId,
                actorUserId: is_string($operatorId)
                    ? $operatorId
                    : null,
                metadata: [
                    'guardian_id' => $payload['guardian_id'],
                    'student_id' => $payload['student_id'],
                ],
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Student successfully linked to guardian.',
        ]);
    }

    public function destroy(
        DeleteGuardianStudentAssociationRequest $request,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        /** @var array{guardian_id:string,student_id:string} $payload */
        $payload = $request->validated();

        try {
            $detached = $this->guardianStudentRepository
                ->detachStudentFromGuardian(
                    tenantId: $tenantId,
                    guardianId: $payload['guardian_id'],
                    studentId: $payload['student_id'],
                );
        } catch (Throwable $exception) {
            $this->logUnexpectedFailure(
                operation: 'detach',
                tenantId: $tenantId,
                operatorId: $operatorId,
                exception: $exception,
            );

            return $this->internalErrorResponse();
        }

        if (! $detached) {
            return $this->notFoundResponse();
        }

        $this->auditTrail->log(
            eventType: 'guardian_student.detached',
            description: 'Detached student profile from guardian profile.',
            tenantId: $tenantId,
            actorUserId: is_string($operatorId)
                ? $operatorId
                : null,
            metadata: [
                'guardian_id' => $payload['guardian_id'],
                'student_id' => $payload['student_id'],
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Student link detached from guardian successfully.',
        ]);
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Guardian, student, or association was not found in this tenant.',
        ], 404);
    }

    private function internalErrorResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to process guardian-student association.',
        ], 500);
    }

    private function logUnexpectedFailure(
        string $operation,
        string $tenantId,
        mixed $operatorId,
        Throwable $exception,
    ): void {
        Log::error(
            'Guardian-student association operation failed.',
            [
                'operation' => $operation,
                'tenant_id' => $tenantId,
                'operator_user_id' => is_string($operatorId)
                    ? $operatorId
                    : null,
                'exception_class' => $exception::class,
            ],
        );
    }
}
