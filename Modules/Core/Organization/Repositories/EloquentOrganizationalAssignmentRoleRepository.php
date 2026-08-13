<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Repositories;

use Modules\Core\Organization\Contracts\OrganizationalAssignmentRoleRepositoryInterface;
use Modules\Core\Organization\Models\OrganizationalAssignmentRole;

final class EloquentOrganizationalAssignmentRoleRepository implements
    OrganizationalAssignmentRoleRepositoryInterface
{
    public function findGrant(
        string $organizationalAssignmentId,
        string $roleId,
    ): ?OrganizationalAssignmentRole {
        return OrganizationalAssignmentRole::query()
            ->where(
                'organizational_assignment_id',
                $organizationalAssignmentId,
            )
            ->where('role_id', $roleId)
            ->first();
    }

    public function assignRole(
        string $organizationalAssignmentId,
        string $roleId,
    ): OrganizationalAssignmentRole {
        return OrganizationalAssignmentRole::query()
            ->firstOrCreate([
                'organizational_assignment_id' =>
                    $organizationalAssignmentId,
                'role_id' => $roleId,
            ]);
    }

    public function revokeRole(
        string $organizationalAssignmentId,
        string $roleId,
    ): void {
        OrganizationalAssignmentRole::query()
            ->where(
                'organizational_assignment_id',
                $organizationalAssignmentId,
            )
            ->where('role_id', $roleId)
            ->delete();
    }
}
