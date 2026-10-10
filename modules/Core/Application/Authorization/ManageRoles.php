<?php

namespace Modules\Core\Application\Authorization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Authorization\Exceptions\AccessException;
use Modules\Core\Domain\Authorization\Role;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Tenancy\Membership;

/**
 * Memasang & mencabut role (PRD-000 §5, §7.1).
 *
 *   tenant-wide : role berlaku di seluruh pohon yayasan (membership_roles)
 *   penugasan   : role berlaku di cakupan penugasan itu (organizational_assignment_roles)
 *
 * Memasang dua kali aman (tidak dobel). Perubahan langsung berlaku di request
 * berikutnya karena hak akses selalu dihitung ulang dari database.
 */
final class ManageRoles
{
    /**
     * @throws AccessException
     */
    public function grantTenantRole(Membership $membership, string $roleKey): void
    {
        if (! $membership->isActive()) {
            throw AccessException::membershipInactive();
        }

        $now = now();

        DB::table('membership_roles')->insertOrIgnore([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'role_id' => $this->roleId($roleKey),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function revokeTenantRole(Membership $membership, string $roleKey): void
    {
        DB::table('membership_roles')
            ->where('tenant_id', $membership->tenant_id)
            ->where('membership_id', $membership->id)
            ->where('role_id', $this->roleId($roleKey))
            ->delete();
    }

    /**
     * @throws AccessException
     */
    public function grantAssignmentRole(OrganizationalAssignment $assignment, string $roleKey): void
    {
        if (! $assignment->isActive()) {
            throw AccessException::assignmentInactive();
        }

        $now = now();

        DB::table('organizational_assignment_roles')->insertOrIgnore([
            'tenant_id' => $assignment->tenant_id,
            'organizational_assignment_id' => $assignment->id,
            'role_id' => $this->roleId($roleKey),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function revokeAssignmentRole(OrganizationalAssignment $assignment, string $roleKey): void
    {
        DB::table('organizational_assignment_roles')
            ->where('tenant_id', $assignment->tenant_id)
            ->where('organizational_assignment_id', $assignment->id)
            ->where('role_id', $this->roleId($roleKey))
            ->delete();
    }

    private function roleId(string $roleKey): string
    {
        $roleId = Role::query()->where('key', $roleKey)->value('id');

        if (! is_string($roleId)) {
            throw AccessException::roleNotFound();
        }

        return $roleId;
    }
}
