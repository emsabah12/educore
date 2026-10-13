<?php

namespace Modules\Core\Application\Authorization;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Membership\AssignToOrganization;
use Modules\Core\Domain\Authorization\Exceptions\AccessChangeException;
use Modules\Core\Domain\Authorization\Exceptions\AccessException;
use Modules\Core\Domain\Organization\AssignmentStatus;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Tenancy\Exceptions\MembershipException;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;

/**
 * Penugasan & role seorang anggota, diatur Admin Yayasan dari detail pegawai (PRD-000 §4.7 F5b).
 *
 * Membungkus service F2b/F3 (AssignToOrganization, ManageRoles) dengan aturan halaman:
 * hanya role yang ditawarkan katalog, penugasan wajib dengan satu role, Admin Yayasan tidak bisa
 * mencabut role Admin Yayasan miliknya sendiri, dan Admin Yayasan terakhir tidak bisa dicabut.
 * Hak akses (core.memberships.manage di tingkat Yayasan) dicek di controller.
 */
final class ManageMemberAccess
{
    public const KIND_STRUCTURAL = 'STRUKTURAL';

    public const KIND_FUNCTIONAL = 'FUNGSIONAL';

    public function __construct(
        private readonly AccessCatalog $catalog,
        private readonly ManageRoles $roles,
        private readonly AssignToOrganization $assignToOrganization,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AccessChangeException
     */
    public function grantTenantRole(string $tenantId, string $membershipId, string $roleKey): void
    {
        if (! isset($this->catalog->tenantRoleOptions()[$roleKey])) {
            throw AccessChangeException::roleNotOffered();
        }

        DB::transaction(function () use ($tenantId, $membershipId, $roleKey): void {
            $membership = $this->lockMembership($tenantId, $membershipId);

            if ($membership->status !== MembershipStatus::Active) {
                throw AccessChangeException::membershipInactive();
            }

            if ($this->hasTenantRole($membership, $roleKey)) {
                throw AccessChangeException::tenantRoleAlreadyGranted();
            }

            try {
                $this->roles->grantTenantRole($membership, $roleKey);
            } catch (AccessException $exception) {
                throw AccessChangeException::rule('role', $exception->getMessage());
            }
        });
    }

    /**
     * @param  string|null  $actorMembershipId  membership Admin Yayasan yang sedang bekerja
     *
     * @throws AccessChangeException
     */
    public function revokeTenantRole(string $tenantId, string $membershipId, string $roleKey, ?string $actorMembershipId): void
    {
        DB::transaction(function () use ($tenantId, $membershipId, $roleKey, $actorMembershipId): void {
            $membership = $this->lockMembership($tenantId, $membershipId);

            if (! $this->hasTenantRole($membership, $roleKey)) {
                throw AccessChangeException::tenantRoleNotGranted();
            }

            if ($roleKey === CoreAccess::ROLE_ADMIN_YAYASAN) {
                if ($membership->id === $actorMembershipId) {
                    throw AccessChangeException::cannotRevokeOwnAdmin();
                }

                if ($this->otherActiveAdmins($membership) === 0) {
                    throw AccessChangeException::lastAdmin();
                }
            }

            $this->roles->revokeTenantRole($membership, $roleKey);
        });
    }

    /**
     * Tambah penugasan struktural (lembaga) atau fungsional (tingkat Yayasan + jenjang), wajib
     * dengan satu role. Penugasan ke target yang sama yang pernah dicabut diaktifkan kembali
     * [ASUMSI F5b, disetujui owner 2026-10-14], karena database melarang penugasan dobel.
     *
     * @throws AccessChangeException
     */
    public function addAssignment(
        string $tenantId,
        string $membershipId,
        string $kind,
        ?string $organizationId,
        ?string $jenjang,
        string $roleKey,
    ): OrganizationalAssignment {
        [$organizationId, $jenjangFilter] = $this->target($kind, $organizationId, $jenjang);

        if (! isset($this->catalog->assignmentRoleOptions()[$roleKey])) {
            throw AccessChangeException::roleNotOffered();
        }

        return DB::transaction(function () use ($tenantId, $membershipId, $organizationId, $jenjangFilter, $roleKey): OrganizationalAssignment {
            $membership = $this->lockMembership($tenantId, $membershipId);

            if ($membership->status !== MembershipStatus::Active) {
                throw AccessChangeException::membershipInactive();
            }

            $existing = OrganizationalAssignment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('membership_id', $membership->id)
                ->where('organization_id', $organizationId)
                ->where('jenjang_filter', $jenjangFilter?->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isActive()) {
                throw AccessChangeException::duplicateAssignment();
            }

            $assignment = $existing === null
                ? $this->createAssignment($membership, $organizationId, $jenjangFilter)
                : $this->reactivate($existing);

            try {
                $this->roles->grantAssignmentRole($assignment, $roleKey);
            } catch (AccessException $exception) {
                throw AccessChangeException::rule('role', $exception->getMessage());
            }

            return $assignment;
        });
    }

    /**
     * Cabut penugasan: semua role-nya dilepas dan statusnya nonaktif (baris tidak dihapus).
     * Berlaku di request berikutnya karena hak akses dihitung ulang tiap request (PRD-000 §6).
     *
     * @throws AccessChangeException
     */
    public function revokeAssignment(string $tenantId, string $membershipId, string $assignmentId): void
    {
        DB::transaction(function () use ($tenantId, $membershipId, $assignmentId): void {
            $membership = $this->lockMembership($tenantId, $membershipId);

            $assignment = Str::isUuid($assignmentId)
                ? OrganizationalAssignment::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('membership_id', $membership->id)
                    ->whereKey($assignmentId)
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $assignment instanceof OrganizationalAssignment) {
                throw (new ModelNotFoundException)->setModel(OrganizationalAssignment::class);
            }

            if (! $assignment->isActive()) {
                throw AccessChangeException::assignmentAlreadyRevoked();
            }

            $roleKeys = DB::table('organizational_assignment_roles')
                ->join('roles', 'roles.id', '=', 'organizational_assignment_roles.role_id')
                ->where('organizational_assignment_roles.organizational_assignment_id', $assignment->id)
                ->orderBy('roles.key')
                ->pluck('roles.key')
                ->map(fn (mixed $key): string => (string) $key)
                ->all();

            foreach ($roleKeys as $roleKey) {
                $this->roles->revokeAssignmentRole($assignment, $roleKey);
            }

            $assignment->status = AssignmentStatus::Inactive;
            $assignment->save();

            $this->audit->record(
                AuditAction::ASSIGNMENT_REVOKED,
                AuditLogger::SUBJECT_ASSIGNMENT,
                $assignment->id,
                [
                    'membership_id' => $membership->id,
                    'jenjang_filter' => $assignment->jenjang_filter?->value,
                    'roles' => $roleKeys,
                ],
                organizationId: $assignment->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Rapikan isian form menjadi target penugasan (organization_id, jenjang_filter).
     *
     * @return array{0: string|null, 1: Jenjang|null}
     *
     * @throws AccessChangeException
     */
    private function target(string $kind, ?string $organizationId, ?string $jenjang): array
    {
        if ($kind === self::KIND_STRUCTURAL) {
            if ($organizationId === null || $organizationId === '') {
                throw AccessChangeException::organizationRequired();
            }

            return [$organizationId, null];
        }

        if ($kind === self::KIND_FUNCTIONAL) {
            $jenjangFilter = $jenjang === null ? null : Jenjang::tryFrom($jenjang);

            if ($jenjangFilter === null) {
                throw AccessChangeException::jenjangRequired();
            }

            return [null, $jenjangFilter];
        }

        throw AccessChangeException::assignmentKindInvalid();
    }

    /**
     * @throws AccessChangeException
     */
    private function createAssignment(Membership $membership, ?string $organizationId, ?Jenjang $jenjangFilter): OrganizationalAssignment
    {
        try {
            return $this->assignToOrganization->handle($membership, $organizationId, $jenjangFilter);
        } catch (MembershipException $exception) {
            throw AccessChangeException::rule($organizationId === null ? 'form' : 'organization_id', $exception->getMessage());
        }
    }

    /**
     * @throws AccessChangeException
     */
    private function reactivate(OrganizationalAssignment $assignment): OrganizationalAssignment
    {
        if ($assignment->organization_id !== null) {
            $organization = Organization::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $assignment->tenant_id)
                ->whereKey($assignment->organization_id)
                ->first();

            if (! $organization instanceof Organization || ! $organization->isActive()) {
                throw AccessChangeException::rule('organization_id', MembershipException::organizationInactive()->getMessage());
            }
        }

        $assignment->status = AssignmentStatus::Active;
        $assignment->save();

        $this->audit->record(
            AuditAction::ASSIGNMENT_CREATED,
            AuditLogger::SUBJECT_ASSIGNMENT,
            $assignment->id,
            [
                'membership_id' => $assignment->membership_id,
                'jenjang_filter' => $assignment->jenjang_filter?->value,
                'diaktifkan_kembali' => true,
            ],
            organizationId: $assignment->organization_id,
            tenantId: $assignment->tenant_id,
        );

        return $assignment;
    }

    private function hasTenantRole(Membership $membership, string $roleKey): bool
    {
        return DB::table('membership_roles')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('membership_roles.tenant_id', $membership->tenant_id)
            ->where('membership_roles.membership_id', $membership->id)
            ->where('roles.key', $roleKey)
            ->exists();
    }

    /**
     * Jumlah anggota aktif LAIN yang memegang role Admin Yayasan. Baris role dikunci agar dua
     * admin yang saling mencabut bersamaan tidak membuat yayasan tanpa admin.
     */
    private function otherActiveAdmins(Membership $membership): int
    {
        $adminMembershipIds = DB::table('membership_roles')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('membership_roles.tenant_id', $membership->tenant_id)
            ->where('roles.key', CoreAccess::ROLE_ADMIN_YAYASAN)
            ->lockForUpdate()
            ->pluck('membership_roles.membership_id')
            ->all();

        return Membership::query()
            ->whereIn('id', $adminMembershipIds)
            ->where('id', '!=', $membership->id)
            ->where('status', MembershipStatus::Active->value)
            ->count();
    }

    private function lockMembership(string $tenantId, string $membershipId): Membership
    {
        if (! Str::isUuid($membershipId)) {
            throw (new ModelNotFoundException)->setModel(Membership::class);
        }

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($membershipId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
