<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Academic\Contracts\StudentRepositoryInterface;
use Modules\Academic\Http\Requests\StoreStudentRequest;
use Modules\Academic\Services\StudentProvisioningService;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Throwable;

final class StudentManagementController extends Controller
{
    public function __construct(
        private readonly StudentRepositoryInterface $studentRepository,
        private readonly StudentProvisioningService $studentProvisioningService,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Tenant context missing.',
            ], 401);
        }

        $perPage = max(
            1,
            min(
                (int) $request->query('per_page', '15'),
                100,
            ),
        );

        $students = $this->studentRepository
            ->getByTenantPaginated(
                $tenantId,
                $perPage,
            );

        return response()->json([
            'status' => 'success',
            'data' => $students->items(),
            'meta' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ],
        ]);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
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

        /** @var array{nama:string,class_id:string,nis?:string|null,nisn?:string|null} $payload */
        $payload = $request->validated();

        try {
            $student = $this->studentProvisioningService
                ->provision(
                    tenantId: $tenantId,
                    data: $payload,
                );
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Target academic class was not found in this tenant.',
            ], 404);
        } catch (Throwable $exception) {
            Log::error(
                'Student provisioning failed.',
                [
                    'tenant_id' => $tenantId,
                    'operator_user_id' => is_string($operatorId)
                        ? $operatorId
                        : null,
                    'exception_class' => $exception::class,
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to persist student record.',
            ], 500);
        }

        $this->auditTrail->log(
            eventType: 'student.created',
            description: 'Created student profile.',
            tenantId: $tenantId,
            actorUserId: is_string($operatorId)
                ? $operatorId
                : null,
            metadata: [
                'student_id' => $student['student_id'],
                'membership_id' => $student['membership_id'],
                'person_id' => $student['person_id'],
                'class_id' => $student['class_id'],
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Student registered successfully within tenant domain.',
            'data' => $student,
        ], 201);
    }
}
