<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Contracts;

use Modules\Core\Organization\Models\OrganizationalAssignmentRole;

interface OrganizationalRoleGrantServiceInterface
{
    public function assignRole(
        string $organizationalAssignmentId,
        string $roleId,
    ): OrganizationalAssignmentRole;

    public function revokeRole(
        string $organizationalAssignmentId,
        string $roleId,
    ): void;
}
