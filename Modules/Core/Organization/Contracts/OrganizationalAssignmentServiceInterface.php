<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Contracts;

use Modules\Core\Organization\Models\OrganizationalAssignment;

interface OrganizationalAssignmentServiceInterface
{
    public function assignToOrganization(
        string $membershipId,
        string $organizationId,
    ): OrganizationalAssignment;

    public function assignToUnit(
        string $membershipId,
        string $organizationId,
        string $organizationUnitId,
    ): OrganizationalAssignment;

    public function deactivate(
        string $assignmentId,
    ): OrganizationalAssignment;
}
