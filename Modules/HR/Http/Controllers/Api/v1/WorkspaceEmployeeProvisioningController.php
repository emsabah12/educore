<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Http\Requests\StoreWorkspaceEmployeeRequest;
use Modules\HR\Services\WorkspaceEmployeeProvisioningService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-017 §3 — Workspace Employee Creation (resolves HR-013 §35).
 */
final class WorkspaceEmployeeProvisioningController extends Controller
{
    public function __construct(
        private readonly WorkspaceEmployeeProvisioningService $workspaceEmployeeProvisioningService,
        private readonly OrganizationalContextInterface $organizationalContext,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    public function store(StoreWorkspaceEmployeeRequest $request): JsonResponse
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

        $context = $this->organizationalContext->getCurrentContext();

        // Secara normal MUSTAHIL null di sini (InjectOrganizationalContext
        // sudah menolak request lebih dulu kalau resolusi gagal) — tetap
        // dijaga fail-closed, konsisten dengan pola di seluruh modul ini.
        if ($context === null) {
            return ApiErrorResponse::make(
                code: 'ORGANIZATIONAL_CONTEXT_REQUIRED',
                message: 'Organizational workspace context is required for this operation.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        /**
         * @var array{
         *     nama: string,
         *     nip: string,
         *     jabatan: string,
         *     employment_type_id: string,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $result = $this->workspaceEmployeeProvisioningService->provisionWithinWorkspace(
                tenantId: $tenantId,
                employeeData: $payload,
                organizationId: $context->organizationId,
                organizationUnitId: $context->organizationUnitId,
            );
        } catch (EmploymentLifecycleException $exception) {
            return ApiErrorResponse::make(
                code: 'WORKSPACE_EMPLOYEE_PROVISIONING_CONFLICT',
                message: $exception->getMessage(),
                status: Response::HTTP_CONFLICT,
            );
        } catch (LogicException $exception) {
            // Pelanggaran kontrak internal (mis. tenant context mismatch)
            // — bukan kesalahan input pengguna. Dicatat untuk developer,
            // dilaporkan ke client sebagai 500 generik supaya tidak
            // membocorkan detail internal.
            Log::error(
                'WorkspaceEmployeeProvisioningService internal contract violation.',
                [
                    'tenant_id' => $tenantId,
                    'exception_message' => $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'WORKSPACE_EMPLOYEE_PROVISIONING_FAILED',
                message: 'Failed to provision workspace employee record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Workspace employee provisioning failed.',
                [
                    'tenant_id' => $tenantId,
                    'operator_user_id' => $operatorId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'WORKSPACE_EMPLOYEE_PROVISIONING_FAILED',
                message: 'Failed to provision workspace employee record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            $this->auditTrail->log(
                eventType: 'employee.workspace_provisioned',
                description: 'Created Employee within an organizational workspace (Employee + Employment + Placement).',
                tenantId: $tenantId,
                actorUserId: $operatorId,
                metadata: [
                    'employee_id' => $result['employee_id'],
                    'membership_id' => $result['membership_id'],
                    'employment_id' => $result['employment_id'],
                    'organizational_assignment_id' => $result['organizational_assignment_id'],
                    'employment_placement_id' => $result['employment_placement_id'],
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Employee provisioned within workspace with ACTIVE Employment and open Placement.',
            'data' => $result,
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
