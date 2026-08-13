<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentServiceInterface;
use Modules\Core\Organization\Exceptions\OrganizationalAssignmentException;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;

final readonly class OrganizationalAssignmentService implements
    OrganizationalAssignmentServiceInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private MembershipRepositoryInterface $membershipRepository,
        private OrganizationalAssignmentRepositoryInterface $assignmentRepository,
    ) {
    }

    public function assignToOrganization(
        string $membershipId,
        string $organizationId,
    ): OrganizationalAssignment {
        $tenantId = $this->resolveTenantId();

        $membershipId = $this->requireUuidV7(
            $membershipId,
            'membership',
        );
        $organizationId = $this->requireUuidV7(
            $organizationId,
            'organization',
        );

        return DB::transaction(function () use (
            $tenantId,
            $membershipId,
            $organizationId,
        ): OrganizationalAssignment {
            $this->requireActiveMembership(
                $membershipId,
                $tenantId,
            );

            $this->requireActiveOrganization(
                $organizationId,
                $tenantId,
            );

            $assignment = $this->assignmentRepository
                ->findOrganizationAssignment(
                    $tenantId,
                    $membershipId,
                    $organizationId,
                );

            if ($assignment === null) {
                return $this->assignmentRepository
                    ->createOrganizationAssignment(
                        $tenantId,
                        $membershipId,
                        $organizationId,
                    );
            }

            return $this->ensureActive($assignment);
        });
    }

    public function assignToUnit(
        string $membershipId,
        string $organizationId,
        string $organizationUnitId,
    ): OrganizationalAssignment {
        $tenantId = $this->resolveTenantId();

        $membershipId = $this->requireUuidV7(
            $membershipId,
            'membership',
        );
        $organizationId = $this->requireUuidV7(
            $organizationId,
            'organization',
        );
        $organizationUnitId = $this->requireUuidV7(
            $organizationUnitId,
            'organization unit',
        );

        return DB::transaction(function () use (
            $tenantId,
            $membershipId,
            $organizationId,
            $organizationUnitId,
        ): OrganizationalAssignment {
            $this->requireActiveMembership(
                $membershipId,
                $tenantId,
            );

            $this->requireActiveOrganization(
                $organizationId,
                $tenantId,
            );

            $this->requireActiveOrganizationUnit(
                $organizationUnitId,
                $organizationId,
                $tenantId,
            );

            $assignment = $this->assignmentRepository
                ->findUnitAssignment(
                    $tenantId,
                    $membershipId,
                    $organizationId,
                    $organizationUnitId,
                );

            if ($assignment === null) {
                return $this->assignmentRepository
                    ->createUnitAssignment(
                        $tenantId,
                        $membershipId,
                        $organizationId,
                        $organizationUnitId,
                    );
            }

            return $this->ensureActive($assignment);
        });
    }

    public function deactivate(
        string $assignmentId,
    ): OrganizationalAssignment {
        $tenantId = $this->resolveTenantId();
        $assignmentId = $this->requireUuidV7(
            $assignmentId,
            'organizational assignment',
        );

        return DB::transaction(function () use (
            $assignmentId,
            $tenantId,
        ): OrganizationalAssignment {
            $assignment = $this->assignmentRepository
                ->findByIdAndTenant(
                    $assignmentId,
                    $tenantId,
                );

            if ($assignment === null) {
                throw new OrganizationalAssignmentException(
                    'Organizational assignment was not found in the current tenant.',
                );
            }

            if (
                $assignment->status
                === OrganizationalAssignment::STATUS_INACTIVE
            ) {
                return $assignment;
            }

            return $this->assignmentRepository->setStatus(
                $assignment,
                OrganizationalAssignment::STATUS_INACTIVE,
            );
        });
    }

    private function ensureActive(
        OrganizationalAssignment $assignment,
    ): OrganizationalAssignment {
        if (
            $assignment->status
            === OrganizationalAssignment::STATUS_ACTIVE
        ) {
            return $assignment;
        }

        return $this->assignmentRepository->setStatus(
            $assignment,
            OrganizationalAssignment::STATUS_ACTIVE,
        );
    }

    private function requireActiveMembership(
        string $membershipId,
        string $tenantId,
    ): void {
        $membership = $this->membershipRepository
            ->findActiveMembershipByIdAndTenant(
                $membershipId,
                $tenantId,
            );

        if ($membership === null) {
            throw new OrganizationalAssignmentException(
                'Active membership was not found in the current tenant.',
            );
        }
    }

    private function requireActiveOrganization(
        string $organizationId,
        string $tenantId,
    ): Organization {
        $organization = Organization::query()
            ->whereKey($organizationId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if ($organization === null) {
            throw new OrganizationalAssignmentException(
                'Active organization was not found in the current tenant.',
            );
        }

        return $organization;
    }

    private function requireActiveOrganizationUnit(
        string $organizationUnitId,
        string $organizationId,
        string $tenantId,
    ): OrganizationUnit {
        $unit = OrganizationUnit::query()
            ->whereKey($organizationUnitId)
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->first();

        if ($unit === null) {
            throw new OrganizationalAssignmentException(
                'Active organization unit was not found for the selected organization in the current tenant.',
            );
        }

        return $unit;
    }

    private function resolveTenantId(): string
    {
        $tenantId = $this->tenantContext->getCurrentTenantId();

        if (
            ! is_string($tenantId)
            || ! UuidV7::validate($tenantId)
        ) {
            throw new OrganizationalAssignmentException(
                'A valid tenant context is required for organizational assignment operations.',
            );
        }

        return trim($tenantId);
    }

    private function requireUuidV7(
        string $identifier,
        string $label,
    ): string {
        $identifier = trim($identifier);

        if (! UuidV7::validate($identifier)) {
            throw new OrganizationalAssignmentException(
                sprintf(
                    'A valid UUIDv7 %s identifier is required.',
                    $label,
                ),
            );
        }

        return $identifier;
    }
}
