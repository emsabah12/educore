<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Http\Requests\StoreEmployeeRequest;
use Modules\HR\Services\EmployeeProvisioningService;
use Throwable;

final class EmployeeManagementController extends Controller
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employeeRepository,
        private readonly EmployeeProvisioningService $employeeProvisioningService,
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

        $employees = $this->employeeRepository
            ->getByTenantPaginated(
                $tenantId,
                $perPage,
            );

        return response()->json([
            'status' => 'success',
            'data' => $employees->items(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
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

        /** @var array{nama:string,nip:string,jabatan:string} $payload */
        $payload = $request->validated();

        try {
            $employee = $this->employeeProvisioningService
                ->provision(
                    tenantId: $tenantId,
                    data: $payload,
                );
        } catch (Throwable $exception) {
            Log::error(
                'Employee provisioning failed.',
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
                'message' => 'Failed to persist employee record.',
            ], 500);
        }

        $this->auditTrail->log(
            eventType: 'employee.created',
            description: 'Created employee profile.',
            tenantId: $tenantId,
            actorUserId: is_string($operatorId)
                ? $operatorId
                : null,
            metadata: [
                'employee_id' => $employee['employee_id'],
                'membership_id' => $employee['membership_id'],
                'person_id' => $employee['person_id'],
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Employee registered successfully within tenant domain.',
            'data' => $employee,
        ], 201);
    }
}
