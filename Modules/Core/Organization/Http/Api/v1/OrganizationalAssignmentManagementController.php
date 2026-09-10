<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentServiceInterface;
use Modules\Core\Organization\Exceptions\OrganizationalAssignmentException;
use Modules\Core\Organization\Http\Requests\StoreOrganizationalAssignmentRequest;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Assign member" management surface — WHO is currently placed into
 * one Organization (and, optionally, one exact Unit inside it), plus
 * the ability to place/unplace them. Write operations
 * (store/deactivate) are a thin HTTP layer over the existing,
 * already-tested OrganizationalAssignmentService — this controller
 * introduces NO new domain logic (see ADR-018 §2.3 — Membership
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
    public function __construct(
        private readonly OrganizationalAssignmentServiceInterface $assignmentService,
    ) {
    }

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

    public function store(
        StoreOrganizationalAssignmentRequest $request,
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

        $membershipId = $request->string('membership_id')->toString();

        $organizationUnitId = $request->input('organization_unit_id');
        $organizationUnitId = is_string($organizationUnitId)
            && trim($organizationUnitId) !== ''
                ? trim($organizationUnitId)
                : null;

        try {
            $assignment = $organizationUnitId === null
                ? $this->assignmentService->assignToOrganization(
                    $membershipId,
                    $organizationModel->id,
                )
                : $this->assignmentService->assignToUnit(
                    $membershipId,
                    $organizationModel->id,
                    $organizationUnitId,
                );
        } catch (OrganizationalAssignmentException $exception) {
            return $this->assignmentRejectedResponse($exception);
        }

        $assignment->loadMissing(['membership.person', 'organizationUnit']);

        return response()->json(
            [
                'status' => 'success',
                'data' => $this->summary($assignment),
            ],
            Response::HTTP_CREATED,
        );
    }

    public function deactivate(
        Request $request,
        string $organization,
        string $assignment,
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

        $assignmentId = trim($assignment);

        if (! Str::isUuid($assignmentId)) {
            return $this->assignmentNotFoundResponse();
        }

        /*
         * Scoped to (tenant, organization) BEFORE calling the
         * service — an assignment id that is real but belongs to a
         * different Organization than the URL states must be
         * indistinguishable from one that does not exist at all.
         */
        $existingAssignment = OrganizationalAssignment::query()
            ->whereKey($assignmentId)
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationModel->id)
            ->first();

        if ($existingAssignment === null) {
            return $this->assignmentNotFoundResponse();
        }

        try {
            $deactivated = $this->assignmentService->deactivate(
                $assignmentId,
            );
        } catch (OrganizationalAssignmentException $exception) {
            return $this->assignmentRejectedResponse($exception);
        }

        $deactivated->loadMissing(['membership.person', 'organizationUnit']);

        return response()->json([
            'status' => 'success',
            'data' => $this->summary($deactivated),
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

    private function assignmentNotFoundResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'RESOURCE_NOT_FOUND',
            message: 'The requested organizational assignment was not found.',
            status: Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * Mirrors AssignMembershipRoleController's pattern: the real
     * RuntimeException message stays internal (useful for
     * observability) but is never surfaced as public API contract.
     * FormRequest validation is the primary rejection path (422);
     * this only catches a race between validation and execution.
     */
    private function assignmentRejectedResponse(
        OrganizationalAssignmentException $exception,
    ): JsonResponse {
        Log::warning(
            'Organizational assignment operation rejected.',
            [
                'reason' => $exception->getMessage(),
            ],
        );

        return ApiErrorResponse::make(
            code: 'ORGANIZATIONAL_ASSIGNMENT_REJECTED',
            message: 'Requested membership or organization unit is not available.',
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
