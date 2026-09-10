<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Http\Requests\StoreOrganizationRequest;
use Modules\Core\Organization\Models\Organization;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kelola Organisasi milik SATU tenant — level TENANT (bukan
 * organizational-scoped, karena Organisasi itu sendiri belum ada
 * sebelum dibuat di sini; modul lain seperti HR baru bisa dipakai
 * SETELAH minimal satu Organisasi ada dan operator ditempatkan ke
 * situ lewat OrganizationalAssignment — lihat catatan arsitektur
 * "TenantActivationService" (belum dibangun) untuk rencana
 * provisioning otomatis ke depan).
 */
final class OrganizationManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->currentTenantId($request);

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $organizations = Organization::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $organizations->map(
                fn(Organization $organization) => $this->summary($organization),
            ),
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $tenantId = $this->currentTenantId($request);

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $organization = Organization::query()->create([
            'tenant_id' => $tenantId,
            'name' => $request->string('name')->toString(),
            'code' => $request->string('code')->toString() ?: null,
            'is_active' => true,
        ]);

        return response()->json(
            [
                'status' => 'success',
                'data' => $this->summary($organization),
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
     * @return array<string, mixed>
     */
    private function summary(Organization $organization): array
    {
        return [
            'id' => (string) $organization->id,
            'name' => $organization->name,
            'code' => $organization->code,
            'is_active' => $organization->is_active,
            'created_at' => $organization->created_at?->toIso8601String(),
        ];
    }
}
