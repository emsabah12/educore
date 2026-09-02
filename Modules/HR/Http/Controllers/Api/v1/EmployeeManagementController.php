<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Http\Requests\StoreEmployeeRequest;
use Modules\HR\Services\EmployeeProvisioningService;
use Symfony\Component\HttpFoundation\Response;
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

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $operatorId = $this->isCanonicalUuid($operatorId)
            ? $operatorId
            : null;

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
                    'operator_user_id' => $operatorId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'EMPLOYEE_PROVISIONING_FAILED',
                message: 'Failed to persist employee record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        /*
         * ------------------------------------------------------------------
         * Best-Effort Audit Boundary
         * ------------------------------------------------------------------
         *
         * Employee record sudah berhasil dipersist di atas. Kegagalan
         * audit trail (mis. tabel audit_logs bermasalah) tidak boleh
         * mengubah operasi yang sudah sukses menjadi response 500 —
         * itu akan membingungkan client (record ada, tapi API bilang
         * gagal). Kegagalan tetap dilaporkan lewat report() untuk
         * observability.
         */
        try {
            $this->auditTrail->log(
                eventType: 'employee.created',
                description: 'Created employee profile.',
                tenantId: $tenantId,
                actorUserId: $operatorId,
                metadata: [
                    'employee_id' => $employee['employee_id'],
                    'membership_id' => $employee['membership_id'],
                    'person_id' => $employee['person_id'],
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Employee registered successfully within tenant domain.',
            'data' => $employee,
        ], 201);
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
