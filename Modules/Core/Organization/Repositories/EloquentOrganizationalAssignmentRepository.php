<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Repositories;

use Modules\Core\Organization\Contracts\OrganizationalAssignmentRepositoryInterface;
use Modules\Core\Organization\Models\OrganizationalAssignment;

final class EloquentOrganizationalAssignmentRepository implements
    OrganizationalAssignmentRepositoryInterface
{
    public function findOrganizationAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
    ): ?OrganizationalAssignment {
        return OrganizationalAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)
            ->where('organization_id', $organizationId)
            ->whereNull('organization_unit_id')
            ->first();
    }

    public function findUnitAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
        string $organizationUnitId,
    ): ?OrganizationalAssignment {
        return OrganizationalAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)
            ->where('organization_id', $organizationId)
            ->where('organization_unit_id', $organizationUnitId)
            ->first();
    }

    public function findByIdAndTenant(
        string $assignmentId,
        string $tenantId,
    ): ?OrganizationalAssignment {
        return OrganizationalAssignment::query()
            ->whereKey($assignmentId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function findByIdForMembershipAndTenant(
        string $assignmentId,
        string $membershipId,
        string $tenantId,
    ): ?OrganizationalAssignment {
        return OrganizationalAssignment::query()
            ->whereKey($assignmentId)
            ->where('membership_id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function createOrganizationAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
    ): OrganizationalAssignment {
        return OrganizationalAssignment::query()->create([
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'organization_unit_id' => null,
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);
    }

    public function createUnitAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
        string $organizationUnitId,
    ): OrganizationalAssignment {
        return OrganizationalAssignment::query()->create([
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'organization_unit_id' => $organizationUnitId,
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);
    }

    public function setStatus(
        OrganizationalAssignment $assignment,
        string $status,
    ): OrganizationalAssignment {
        $assignment->setAttribute('status', $status);
        $assignment->save();

        return $assignment->refresh();
    }
}
