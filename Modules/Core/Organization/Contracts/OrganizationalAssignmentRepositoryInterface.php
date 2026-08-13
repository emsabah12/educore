<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Contracts;

use Modules\Core\Organization\Models\OrganizationalAssignment;

interface OrganizationalAssignmentRepositoryInterface
{
    public function findOrganizationAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
    ): ?OrganizationalAssignment;

    public function findUnitAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
        string $organizationUnitId,
    ): ?OrganizationalAssignment;

    public function findByIdAndTenant(
        string $assignmentId,
        string $tenantId,
    ): ?OrganizationalAssignment;

    public function findByIdForMembershipAndTenant(
        string $assignmentId,
        string $membershipId,
        string $tenantId,
    ): ?OrganizationalAssignment;

    public function createOrganizationAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
    ): OrganizationalAssignment;

    public function createUnitAssignment(
        string $tenantId,
        string $membershipId,
        string $organizationId,
        string $organizationUnitId,
    ): OrganizationalAssignment;

    public function setStatus(
        OrganizationalAssignment $assignment,
        string $status,
    ): OrganizationalAssignment;
}
