<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Http\Requests\StoreOrganizationUnitRequest;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationUnit;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kelola Unit di bawah SATU Organization milik tenant — mengikuti
 * topologi tetap ADR-018 (`Tenant → Organization → OrganizationUnit`,
 * non-rekursif). Selalu nested di bawah Organization; tidak ada
 * koleksi Unit lintas-Organization.
 *
 * Sama seperti OrganizationManagementController, ini level TENANT
 * (digate oleh organization.units.manage, bukan
 * organizational.permission) — operator mengelola struktur SEBELUM
 * ada OrganizationalAssignment yang menempatkan siapa pun ke sana.
 */
final class OrganizationUnitManagementController extends Controller
{
    public function index(
        Request $request,
        string $organization,
    ): JsonResponse {
        $tenantId = $this->currentTenantId($request);

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $organizationModel = $this->requireOrganization(
            $organization,
            $tenantId,
        );

        if ($organizationModel === null) {
            return $this->organizationNotFoundResponse();
        }

        $units = OrganizationUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationModel->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $units->map(
                fn(OrganizationUnit $unit) => $this->summary($unit),
            ),
        ]);
    }

    public function store(
        StoreOrganizationUnitRequest $request,
        string $organization,
    ): JsonResponse {
        $tenantId = $this->currentTenantId($request);

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $organizationModel = $this->requireOrganization(
            $organization,
            $tenantId,
        );

        if ($organizationModel === null) {
            return $this->organizationNotFoundResponse();
        }

        $unit = OrganizationUnit::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organizationModel->id,
            'name' => $request->string('name')->toString(),
            'code' => $request->string('code')->toString() ?: null,
            'is_active' => true,
        ]);

        return response()->json(
            [
                'status' => 'success',
                'data' => $this->summary($unit),
            ],
            Response::HTTP_CREATED,
        );
    }

    private function currentTenantId(Request $request): string
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        return is_string($tenantId) ? $tenantId : '';
    }

    private function requireOrganization(
        string $organizationId,
        string $tenantId,
    ): ?Organization {
        $organizationId = trim($organizationId);

        if (! Str::isUuid($organizationId)) {
            return null;
        }

        return Organization::query()
            ->whereKey($organizationId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isCanonicalUuid(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
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

    /**
     * Organization tidak ditemukan ATAU bukan milik tenant saat ini
     * selalu mengembalikan respons yang identik — eksistensi Organization
     * milik tenant lain tidak boleh bisa dibedakan lewat status code.
     */
    private function organizationNotFoundResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'RESOURCE_NOT_FOUND',
            message: 'The requested organization was not found.',
            status: Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(OrganizationUnit $unit): array
    {
        return [
            'id' => (string) $unit->id,
            'organization_id' => (string) $unit->organization_id,
            'name' => $unit->name,
            'code' => $unit->code,
            'is_active' => $unit->is_active,
            'created_at' => $unit->created_at?->toIso8601String(),
        ];
    }
}
