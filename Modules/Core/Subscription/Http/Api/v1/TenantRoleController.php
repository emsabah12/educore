<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Subscription\Exceptions\CustomRoleFeatureNotAvailableException;
use Modules\Core\Subscription\Http\Requests\StoreTenantRoleRequest;
use Modules\Core\Subscription\Http\Requests\UpdateTenantRolePermissionsRequest;
use Modules\Core\Subscription\Services\TenantRoleService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role KUSTOM milik SATU tenant — berbeda dari
 * `RoleCatalogController` (katalog role GLOBAL, read-only, semua
 * tenant boleh baca) dan `PlatformRoleController` (katalog global,
 * cuma superadmin). Setiap query di sini WAJIB discope
 * `tenant_id === $currentTenantId` — role kustom tenant lain tidak
 * pernah boleh terlihat/terjangkau dari sini.
 */
final class TenantRoleController extends Controller
{
    public function __construct(
        private readonly TenantRoleService $tenantRoleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId($request);

        $roles = $this->tenantRoleService->listCustomRoles($tenantId);

        return response()->json([
            'status' => 'success',
            'data' => $roles->map(
                fn(Role $role) => $this->roleSummary($role),
            ),
        ]);
    }

    /**
     * Katalog permission yang boleh dipilih tenant saat membuat/
     * mengedit role kustom — lihat catatan cakupan di
     * `TenantRoleService::assignablePermissions()`.
     */
    public function assignablePermissions(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId($request);

        $permissions = $this->tenantRoleService->assignablePermissions($tenantId);

        return response()->json([
            'status' => 'success',
            'data' => $permissions->map(fn($permission) => [
                'id' => (string) $permission->id,
                'name' => $permission->name,
                'display_name' => $permission->display_name,
                'module' => $permission->module,
            ]),
        ]);
    }

    public function store(StoreTenantRoleRequest $request): JsonResponse
    {
        $tenantId = $this->currentTenantId($request);

        try {
            $role = $this->tenantRoleService->createCustomRole(
                $tenantId,
                $request->string('name')->toString(),
                $request->string('display_name')->toString(),
                $request->string('description')->toString() ?: null,
            );
        } catch (CustomRoleFeatureNotAvailableException) {
            return ApiErrorResponse::make(
                code: 'CUSTOM_ROLE_FEATURE_NOT_AVAILABLE',
                message: 'Tenant Anda saat ini tidak memiliki fitur Custom Role yang aktif.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return response()->json(
            [
                'status' => 'success',
                'data' => $this->roleDetail($role),
            ],
            Response::HTTP_CREATED,
        );
    }

    public function show(Request $request, string $roleId): JsonResponse
    {
        $role = $this->findTenantRoleOrFail($request, $roleId);

        return response()->json([
            'status' => 'success',
            'data' => $this->roleDetail($role),
        ]);
    }

    public function update(
        UpdateTenantRolePermissionsRequest $request,
        string $roleId,
    ): JsonResponse {
        $role = $this->findTenantRoleOrFail($request, $roleId);

        if ($this->tenantRoleService->visibilityStateFor($role) !== 'active') {
            return ApiErrorResponse::make(
                code: 'CUSTOM_ROLE_NOT_EDITABLE',
                message: 'Role kustom ini sedang tidak bisa diedit karena fitur Custom Role tidak aktif untuk tenant Anda.',
                status: Response::HTTP_CONFLICT,
            );
        }

        $this->tenantRoleService->syncPermissions(
            $role,
            $request->input('permission_ids', []),
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->roleDetail($role->fresh(['permissions'])),
        ]);
    }

    private function findTenantRoleOrFail(Request $request, string $roleId): Role
    {
        $tenantId = $this->currentTenantId($request);

        return Role::query()
            ->where('tenant_id', $tenantId)
            ->with('permissions')
            ->findOrFail($roleId);
    }

    private function currentTenantId(Request $request): string
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        return is_string($tenantId) ? $tenantId : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function roleSummary(Role $role): array
    {
        return [
            'id' => (string) $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'permission_count' => $role->permissions_count ?? $role->permissions()->count(),
            'visibility_state' => $this->tenantRoleService->visibilityStateFor($role),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleDetail(Role $role): array
    {
        return [
            'id' => (string) $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'visibility_state' => $this->tenantRoleService->visibilityStateFor($role),
            'permissions' => $role->permissions->map(fn($permission) => [
                'id' => (string) $permission->id,
                'name' => $permission->name,
                'display_name' => $permission->display_name,
                'module' => $permission->module,
            ]),
        ];
    }
}
