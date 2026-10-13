<?php

namespace Modules\Core\Application\Authorization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
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
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AccessException
     */
    public function grantTenantRole(Membership $membership, string $roleKey): void
    {
        if (! $membership->isActive()) {
            throw AccessException::membershipInactive();
        }

        $roleId = $this->roleId($roleKey);

        // Perubahan role dan catatan auditnya selalu tersimpan bersamaan.
        DB::transaction(function () use ($membership, $roleId, $roleKey): void {
            $now = now();

            $inserted = DB::table('membership_roles')->insertOrIgnore([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Hanya dicatat bila memang ada perubahan (memasang dua kali tidak menambah log).
            if ($inserted > 0) {
                $this->recordMembershipRole(AuditAction::ROLE_GRANTED, $membership, $roleKey);
            }
        });
    }

    public function revokeTenantRole(Membership $membership, string $roleKey): void
    {
        $roleId = $this->roleId($roleKey);

        DB::transaction(function () use ($membership, $roleId, $roleKey): void {
            $deleted = DB::table('membership_roles')
                ->where('tenant_id', $membership->tenant_id)
                ->where('membership_id', $membership->id)
                ->where('role_id', $roleId)
                ->delete();

            if ($deleted > 0) {
                $this->recordMembershipRole(AuditAction::ROLE_REVOKED, $membership, $roleKey);
            }
        });
    }

    /**
     * @throws AccessException
     */
    public function grantAssignmentRole(OrganizationalAssignment $assignment, string $roleKey): void
    {
        if (! $assignment->isActive()) {
            throw AccessException::assignmentInactive();
        }

        $roleId = $this->roleId($roleKey);

        DB::transaction(function () use ($assignment, $roleId, $roleKey): void {
            $now = now();

            $inserted = DB::table('organizational_assignment_roles')->insertOrIgnore([
                'tenant_id' => $assignment->tenant_id,
                'organizational_assignment_id' => $assignment->id,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted > 0) {
                $this->recordAssignmentRole(AuditAction::ROLE_GRANTED, $assignment, $roleKey);
            }
        });
    }

    public function revokeAssignmentRole(OrganizationalAssignment $assignment, string $roleKey): void
    {
        $roleId = $this->roleId($roleKey);

        DB::transaction(function () use ($assignment, $roleId, $roleKey): void {
            $deleted = DB::table('organizational_assignment_roles')
                ->where('tenant_id', $assignment->tenant_id)
                ->where('organizational_assignment_id', $assignment->id)
                ->where('role_id', $roleId)
                ->delete();

            if ($deleted > 0) {
                $this->recordAssignmentRole(AuditAction::ROLE_REVOKED, $assignment, $roleKey);
            }
        });
    }

    private function recordMembershipRole(string $action, Membership $membership, string $roleKey): void
    {
        $this->audit->record(
            $action,
            AuditLogger::SUBJECT_MEMBERSHIP,
            $membership->id,
            ['role' => $roleKey, 'scope' => 'tenant'],
            tenantId: $membership->tenant_id,
        );
    }

    private function recordAssignmentRole(string $action, OrganizationalAssignment $assignment, string $roleKey): void
    {
        $this->audit->record(
            $action,
            AuditLogger::SUBJECT_ASSIGNMENT,
            $assignment->id,
            ['role' => $roleKey, 'scope' => 'assignment', 'jenjang_filter' => $assignment->jenjang_filter?->value],
            organizationId: $assignment->organization_id,
            tenantId: $assignment->tenant_id,
        );
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
