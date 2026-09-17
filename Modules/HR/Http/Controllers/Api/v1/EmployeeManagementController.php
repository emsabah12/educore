<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Exceptions\EmployeeAccountConflictException;
use Modules\HR\Http\Requests\CreateEmployeeAccountRequest;
use Modules\HR\Http\Requests\StoreEmployeeRequest;
use Modules\HR\Services\EmployeeAccountProvisioningService;
use Modules\HR\Services\EmployeeProvisioningService;
use Modules\HR\Services\HrWorkforceScopeService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EmployeeManagementController extends Controller
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employeeRepository,
        private readonly EmployeeProvisioningService $employeeProvisioningService,
        private readonly EmployeeAccountProvisioningService $employeeAccountProvisioningService,
        private readonly AuditTrailServiceInterface $auditTrail,
        private readonly HrWorkforceScopeService $hrWorkforceScopeService,
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

    /**
     * HR-017 §2 — Workspace Employee Listing.
     *
     * Berbeda dari index() tenant-wide di atas: query di sini SUDAH
     * difilter di level SQL sesuai scope organisasi/unit aktif
     * (HrWorkforceScopeService::visibleEmployeesQuery(), menegakkan
     * HR-013 §31 Collection Query Rule — TIDAK PERNAH mengambil semua
     * Employee tenant lalu memfilter belakangan).
     */
    public function indexWorkspace(Request $request): JsonResponse
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

        $employees = $this->hrWorkforceScopeService
            ->visibleEmployeesQuery($tenantId)
            ->orderByDesc('created_at')
            ->paginate($perPage);

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

    /**
     * HR-017 §2 — Workspace Employee Detail.
     *
     * Sengaja memfilter dari `visibleEmployeesQuery()` yang SAMA PERSIS
     * dipakai `indexWorkspace()` (bukan query terpisah) — jadi aturan
     * "siapa boleh lihat siapa" otomatis identik antara listing dan
     * detail, tidak ada risiko drift antara dua endpoint. Employee di
     * luar workspace operator akan mengembalikan 404 yang sama seperti
     * Employee yang benar-benar tidak ada — tidak membocorkan
     * keberadaannya.
     */
    public function showWorkspace(
        Request $request,
        string $employeeId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $employee = $this->hrWorkforceScopeService
            ->visibleEmployeesQuery($tenantId)
            ->with([
                'employments' => fn ($query) => $query
                    ->with('employmentType')
                    ->orderByDesc('start_date'),
            ])
            ->where('employees.id', $employeeId)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (string) $employee->id,
                'tenant_id' => (string) $employee->tenant_id,
                'membership_id' => (string) $employee->membership_id,
                'nip' => $employee->nip,
                'jabatan' => $employee->jabatan,
                'nama' => $employee->nama,
                'created_at' => $employee->created_at,
                'updated_at' => $employee->updated_at,
                'employments' => $employee->employments->map(
                    fn ($employment) => [
                        'id' => (string) $employment->id,
                        'employment_type' => $employment->employmentType?->name,
                        'status' => $employment->status,
                        'start_date' => $employment->start_date?->format('Y-m-d'),
                        'end_date' => $employment->end_date?->format('Y-m-d'),
                    ],
                ),
            ],
        ]);
    }

    /**
     * §Pengaturan Akun Pegawai — resolusi employeeId -> membership_id
     * -> person_id memakai `visibleEmployeesQuery()` yang SAMA PERSIS
     * dipakai indexWorkspace()/showWorkspace() — Employee di luar
     * workspace operator mengembalikan 404 yang sama seperti Employee
     * yang tidak ada, konsisten dengan pola showWorkspace().
     *
     * Password yang di-generate HANYA muncul di response ini — tidak
     * pernah disimpan di audit trail atau log manapun.
     */
    public function createAccount(
        CreateEmployeeAccountRequest $request,
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

        $employee = $this->hrWorkforceScopeService
            ->visibleEmployeesQuery($tenantId)
            ->where('employees.id', $employeeId)
            ->first();

        if ($employee === null) {
            return ApiErrorResponse::make(
                code: 'EMPLOYEE_NOT_FOUND',
                message: 'Employee was not found in the current workspace.',
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $personId = Membership::query()
            ->where('id', $employee->membership_id)
            ->value('person_id');

        if (! $this->isCanonicalUuid($personId)) {
            return ApiErrorResponse::make(
                code: 'EMPLOYEE_NOT_FOUND',
                message: 'Employee was not found in the current workspace.',
                status: Response::HTTP_NOT_FOUND,
            );
        }

        /** @var array{email: string} $payload */
        $payload = $request->validated();

        try {
            $account = $this->employeeAccountProvisioningService
                ->createAccountForEmployee(
                    personId: $personId,
                    email: $payload['email'],
                );
        } catch (EmployeeAccountConflictException $exception) {
            return ApiErrorResponse::make(
                code: 'EMPLOYEE_ACCOUNT_CONFLICT',
                message: $exception->getMessage(),
                status: Response::HTTP_CONFLICT,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Employee account creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'operator_user_id' => $operatorId,
                    'employee_id' => $employeeId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'EMPLOYEE_ACCOUNT_CREATION_FAILED',
                message: 'Failed to create login account for employee.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            $this->auditTrail->log(
                eventType: 'employee.account_created',
                description: 'Created login account for employee.',
                tenantId: $tenantId,
                actorUserId: $operatorId,
                metadata: [
                    'employee_id' => $employeeId,
                    // SENGAJA TIDAK menyertakan generated_password —
                    // audit trail bukan tempat menyimpan rahasia.
                    'user_id' => $account['user_id'],
                    'email' => $account['email'],
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Login account created. The generated password is shown only once.',
            'data' => $account,
        ], 201);
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
