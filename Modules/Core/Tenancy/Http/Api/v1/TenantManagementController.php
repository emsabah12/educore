<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Tenancy\Contracts\TenantRepositoryInterface;
use Modules\Core\Tenancy\Exceptions\InvalidInitialTenantAdminException;
use Modules\Core\Tenancy\Services\TenantProvisioningService;
use Modules\Core\Tenancy\Http\Requests\UpdateTenantRequest;
use Modules\Core\Tenancy\Http\Requests\ListTenantsRequest;
use Modules\Core\Tenancy\Http\Requests\StoreTenantRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TenantManagementController extends Controller
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantProvisioningService $tenantProvisioningService,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    /**
     * Menampilkan daftar seluruh tenant dengan pagination.
     */
    public function index(
        ListTenantsRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $perPage = (int) (
            $validated['per_page'] ?? 15
        );

        $operatorId = $this->resolveOperatorId(
            $request,
        );

        try {
            $tenants = $this->tenantRepository
                ->getAllPaginated($perPage);
        } catch (Throwable $exception) {
            $this->logOperationFailure(
                exception: $exception,
                operation: 'tenant.index',
                operatorId: $operatorId,
            );

            return $this->internalServerErrorResponse(
                'Failed to retrieve tenants.',
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $tenants->items(),
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Mendaftarkan tenant baru.
     */
    public function store(
        StoreTenantRequest $request,
    ): JsonResponse {
        $validated = $request->safe()->only([
            'name',
            'subdomain',
            'is_active',
            'initial_admin_user_id',
        ]);

        $initialAdminUserId = (string) (
            $validated['initial_admin_user_id'] ?? ''
        );

        unset($validated['initial_admin_user_id']);

        $operatorId = $this->resolveOperatorId(
            $request,
        );

        try {
            $result = $this->tenantProvisioningService->provision(
                $validated,
                $initialAdminUserId,
            );
        } catch (InvalidInitialTenantAdminException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected initial admin user is not eligible.',
                'errors' => [
                    'initial_admin_user_id' => [
                        $exception->getMessage(),
                    ],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $exception) {
            $this->logOperationFailure(
                exception: $exception,
                operation: 'tenant.create',
                operatorId: $operatorId,
            );

            return $this->internalServerErrorResponse(
                'Failed to register tenant.',
            );
        }

        $tenant = $result['tenant'];
        $initialAdmin = $result['initial_admin'];
        $tenantId = (string) $tenant['id'];

        $auditPayload = $validated;
        $auditPayload['initial_admin_user_id'] =
            $initialAdmin['user_id'];
        $auditPayload['initial_admin_membership_id'] =
            $initialAdmin['membership_id'];

        $this->recordAuditSafely(
            eventType: 'tenant.created',
            description: sprintf(
                'Superadmin berhasil mendaftarkan tenant baru: %s (%s)',
                $tenant['name'],
                $tenant['subdomain'],
            ),
            tenantId: $tenantId,
            operatorId: $operatorId,
            payload: $auditPayload,
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Tenant registered successfully.',
            'data' => array_merge(
                $tenant,
                [
                    'initial_admin' => $initialAdmin,
                ],
            ),
        ], Response::HTTP_CREATED);
    }

    /**
     * Memperbarui informasi atau status tenant.
     */
    public function update(
        UpdateTenantRequest $request,
        string $id,
    ): JsonResponse {
        /*
     * Jangan menyertakan route parameter "id" ke repository
     * update payload.
     */
        $payload = $request->safe()->only([
            'name',
            'is_active',
        ]);

        $operatorId = $this->resolveOperatorId(
            $request,
        );

        try {
            $updatedTenant = $this->tenantRepository->update(
                $id,
                $payload,
            );
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tenant not found.',
            ], Response::HTTP_NOT_FOUND);
        } catch (Throwable $exception) {
            $this->logOperationFailure(
                exception: $exception,
                operation: 'tenant.update',
                operatorId: $operatorId,
                tenantId: $id,
            );

            return $this->internalServerErrorResponse(
                'Failed to update tenant.',
            );
        }

        $this->recordAuditSafely(
            eventType: 'tenant.updated',
            description: sprintf(
                'Superadmin memperbarui data tenant ID: %s',
                $id,
            ),
            tenantId: $id,
            operatorId: $operatorId,
            payload: $payload,
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Tenant updated successfully.',
            'data' => $updatedTenant,
        ], Response::HTTP_OK);
    }

    /**
     * Audit bersifat best-effort.
     *
     * Operasi bisnis yang sudah berhasil tidak boleh dilaporkan gagal
     * hanya karena media penyimpanan audit mengalami gangguan.
     *
     * @param array<string, mixed>|null $payload
     */
    private function recordAuditSafely(
        string $eventType,
        string $description,
        ?string $tenantId,
        ?string $operatorId,
        ?array $payload,
    ): void {
        try {
            $this->auditTrail->log(
                $eventType,
                $description,
                $tenantId,
                $operatorId,
                $payload,
            );
        } catch (Throwable $exception) {
            /*
             * Defense-in-depth apabila implementation audit lain
             * tidak menerapkan fail-safe seperti canonical audit persistence.
             */
            Log::critical(
                'Tenant audit trail failed.',
                [
                    'event_type' => $eventType,
                    'tenant_id' => $tenantId,
                    'operator_id' => $operatorId,
                    'exception' => $exception,
                ],
            );
        }
    }

    /**
     * Mengambil operator ID dari canonical request context.
     */
    private function resolveOperatorId(
        Request $request,
    ): ?string {
        $operatorId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! is_string($operatorId)) {
            return null;
        }

        $operatorId = trim($operatorId);

        return $operatorId !== ''
            ? $operatorId
            : null;
    }

    /**
     * Mencatat kegagalan internal tanpa menyimpan bearer token,
     * header, password, atau request payload mentah.
     */
    private function logOperationFailure(
        Throwable $exception,
        string $operation,
        ?string $operatorId,
        ?string $tenantId = null,
    ): void {
        Log::error(
            'Tenant management operation failed.',
            [
                'operation' => $operation,
                'operator_id' => $operatorId,
                'tenant_id' => $tenantId,
                'exception' => $exception,
            ],
        );
    }

    private function internalServerErrorResponse(
        string $message,
    ): JsonResponse {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
