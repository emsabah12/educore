<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read side of "assign member" — listing WHO is currently placed
 * into one Organization (and, optionally, one exact Unit inside
 * it). The write side (create/deactivate an assignment) is a
 * separate operation layered on top of the existing, already-tested
 * OrganizationalAssignmentService (see ADR-018 §2.3 — Membership
 * remains Person × Tenant; OrganizationalAssignment models
 * OPERATIONAL PLACEMENT separately, never a canonical identity
 * relation).
 *
 * Deliberately NOT `organizational.permission` — assigning someone
 * to a place is what CREATES organizational context for them, so
 * this management surface has to live one level up, at TENANT
 * scope, same as OrganizationManagementController and
 * OrganizationUnitManagementController.
 */
final class OrganizationalAssignmentManagementController extends Controller
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

        $unitFilter = $request->query('organization_unit_id');
        $unitFilter = is_string($unitFilter) ? trim($unitFilter) : '';

        $assignments = OrganizationalAssignment::query()
            ->with(['membership.person', 'organizationUnit'])
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationModel->id)
            ->when(
                $unitFilter !== '' && Str::isUuid($unitFilter),
                fn($query) => $query->where(
                    'organization_unit_id',
                    $unitFilter,
                ),
            )
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $assignments->map(
                fn(OrganizationalAssignment $assignment) => $this->summary(
                    $assignment,
                ),
            ),
        ]);
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
    private function summary(OrganizationalAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->id,
            'membership_id' => (string) $assignment->membership_id,
            'membership_name' => $assignment->membership?->person?->name,
            'organization_id' => (string) $assignment->organization_id,
            'organization_unit_id' => $assignment->organization_unit_id,
            'organization_unit_name' => $assignment->organizationUnit?->name,
            'status' => $assignment->status,
            'created_at' => $assignment->created_at?->toIso8601String(),
        ];
    }
}
