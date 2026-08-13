<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentRoleRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalRoleGrantServiceInterface;
use Modules\Core\Organization\Exceptions\OrganizationalRoleGrantException;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationalAssignmentRole;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;

final readonly class OrganizationalRoleGrantService implements
    OrganizationalRoleGrantServiceInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private MembershipRepositoryInterface $membershipRepository,
        private OrganizationalAssignmentRepositoryInterface $assignmentRepository,
        private OrganizationalAssignmentRoleRepositoryInterface $grantRepository,
    ) {
    }

    public function assignRole(
        string $organizationalAssignmentId,
        string $roleId,
    ): OrganizationalAssignmentRole {
        $tenantId = $this->resolveActiveTenantId();

        $assignmentId = $this->requireUuidV7(
            $organizationalAssignmentId,
            'organizational assignment',
        );
        $roleId = $this->requireUuidV7(
            $roleId,
            'role',
        );

        return DB::transaction(function () use (
            $tenantId,
            $assignmentId,
            $roleId,
        ): OrganizationalAssignmentRole {
            $assignment = $this->requireTenantOwnedAssignment(
                $assignmentId,
                $tenantId,
            );

            $this->assertAssignmentIsActive($assignment);
            $this->requireActiveMembership(
                $assignment,
                $tenantId,
            );

            $organizationId = $this->requireStoredUuidV7(
                $assignment->organization_id,
                'organization',
            );

            $this->requireActiveOrganization(
                $organizationId,
                $tenantId,
            );

            $organizationUnitId = $this->resolveOrganizationUnitId(
                $assignment->organization_unit_id,
            );

            if ($organizationUnitId !== null) {
                $this->requireActiveOrganizationUnit(
                    $organizationUnitId,
                    $organizationId,
                    $tenantId,
                );
            }

            $this->requireRole($roleId);

            $existing = $this->grantRepository->findGrant(
                $assignmentId,
                $roleId,
            );

            if ($existing !== null) {
                return $existing;
            }

            return $this->grantRepository->assignRole(
                $assignmentId,
                $roleId,
            );
        });
    }

    public function revokeRole(
        string $organizationalAssignmentId,
        string $roleId,
    ): void {
        $tenantId = $this->resolveActiveTenantId();

        $assignmentId = $this->requireUuidV7(
            $organizationalAssignmentId,
            'organizational assignment',
        );
        $roleId = $this->requireUuidV7(
            $roleId,
            'role',
        );

        DB::transaction(function () use (
            $tenantId,
            $assignmentId,
            $roleId,
        ): void {
            /*
             * Revocation intentionally requires only tenant ownership.
             * Dormant grants on inactive assignments must remain revocable.
             */
            $this->requireTenantOwnedAssignment(
                $assignmentId,
                $tenantId,
            );

            $this->grantRepository->revokeRole(
                $assignmentId,
                $roleId,
            );
        });
    }

    private function resolveActiveTenantId(): string
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        if (! $tenant instanceof Tenant) {
            throw new OrganizationalRoleGrantException(
                'A valid active tenant context is required for organizational role grant operations.',
            );
        }

        $tenantId = trim((string) $tenant->getKey());

        if (
            ! UuidV7::validate($tenantId)
            || ! (bool) $tenant->getAttribute('is_active')
        ) {
            throw new OrganizationalRoleGrantException(
                'A valid active tenant context is required for organizational role grant operations.',
            );
        }

        return $tenantId;
    }

    private function requireTenantOwnedAssignment(
        string $assignmentId,
        string $tenantId,
    ): OrganizationalAssignment {
        $assignment = $this->assignmentRepository
            ->findByIdAndTenant(
                $assignmentId,
                $tenantId,
            );

        if ($assignment === null) {
            throw new OrganizationalRoleGrantException(
                'Organizational assignment was not found in the current tenant.',
            );
        }

        return $assignment;
    }

    private function assertAssignmentIsActive(
        OrganizationalAssignment $assignment,
    ): void {
        if (
            strtoupper(trim((string) $assignment->status))
            !== OrganizationalAssignment::STATUS_ACTIVE
        ) {
            throw new OrganizationalRoleGrantException(
                'Scoped role cannot be assigned to an inactive organizational assignment.',
            );
        }
    }

    private function requireActiveMembership(
        OrganizationalAssignment $assignment,
        string $tenantId,
    ): void {
        $membershipId = $this->requireStoredUuidV7(
            $assignment->membership_id,
            'membership',
        );

        $membership = $this->membershipRepository
            ->findActiveMembershipByIdAndTenant(
                $membershipId,
                $tenantId,
            );

        if ($membership === null) {
            throw new OrganizationalRoleGrantException(
                'Scoped role cannot be assigned because the target membership is inactive or outside the current tenant.',
            );
        }
    }

    private function requireActiveOrganization(
        string $organizationId,
        string $tenantId,
    ): void {
        $exists = Organization::query()
            ->whereKey($organizationId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new OrganizationalRoleGrantException(
                'Scoped role cannot be assigned because the target organization is inactive or outside the current tenant.',
            );
        }
    }

    private function requireActiveOrganizationUnit(
        string $organizationUnitId,
        string $organizationId,
        string $tenantId,
    ): void {
        $exists = OrganizationUnit::query()
            ->whereKey($organizationUnitId)
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new OrganizationalRoleGrantException(
                'Scoped role cannot be assigned because the target organization unit is inactive or does not belong to the selected organization and tenant.',
            );
        }
    }

    private function requireRole(
        string $roleId,
    ): void {
        if (! Role::query()->whereKey($roleId)->exists()) {
            throw new OrganizationalRoleGrantException(
                'Canonical role was not found.',
            );
        }
    }

    private function resolveOrganizationUnitId(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        return $this->requireStoredUuidV7(
            $value,
            'organization unit',
        );
    }

    private function requireStoredUuidV7(
        mixed $identifier,
        string $label,
    ): string {
        if (! is_string($identifier)) {
            throw new OrganizationalRoleGrantException(
                sprintf(
                    'Stored %s identifier is invalid.',
                    $label,
                ),
            );
        }

        return $this->requireUuidV7(
            $identifier,
            $label,
        );
    }

    private function requireUuidV7(
        string $identifier,
        string $label,
    ): string {
        $identifier = trim($identifier);

        if (! UuidV7::validate($identifier)) {
            throw new OrganizationalRoleGrantException(
                sprintf(
                    'A valid UUIDv7 %s identifier is required.',
                    $label,
                ),
            );
        }

        return $identifier;
    }
}
