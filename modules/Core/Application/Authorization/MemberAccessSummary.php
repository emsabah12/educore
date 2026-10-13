<?php

namespace Modules\Core\Application\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Domain\Organization\AssignmentStatus;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\WorkContext;

/**
 * Data role tingkat Yayasan & penugasan seorang anggota untuk bagian "Akses login"
 * di detail pegawai (PRD-000 §4.7 F5b). Hanya dipanggil untuk Admin Yayasan.
 */
final class MemberAccessSummary
{
    public function __construct(
        private readonly AccessCatalog $catalog,
    ) {}

    /**
     * @param  string|null  $viewerMembershipId  membership Admin Yayasan yang sedang melihat
     * @return array{
     *     membershipActive: bool,
     *     tenantRoles: list<array{key: string, name: string, granted: bool, lockedReason: string|null}>,
     *     assignments: list<array{id: string, label: string, functional: bool, roles: list<string>}>,
     *     options: array{
     *         assignmentRoles: list<array{value: string, label: string}>,
     *         organizations: list<array{value: string, label: string}>,
     *         jenjang: list<array{value: string, label: string}>,
     *     },
     * }|null null bila membership tidak ada di yayasan ini
     */
    public function forMembership(string $tenantId, string $membershipId, ?string $viewerMembershipId): ?array
    {
        $membership = Str::isUuid($membershipId)
            ? Membership::query()->where('tenant_id', $tenantId)->whereKey($membershipId)->first()
            : null;

        if (! $membership instanceof Membership) {
            return null;
        }

        return [
            'membershipActive' => $membership->status === MembershipStatus::Active,
            'tenantRoles' => $this->tenantRoles($membership, $viewerMembershipId),
            'assignments' => $this->assignments($membership),
            'options' => [
                'assignmentRoles' => $this->toOptions($this->catalog->assignmentRoleOptions()),
                'organizations' => $this->organizationOptions($membership->tenant_id),
                'jenjang' => array_map(fn (Jenjang $jenjang): array => [
                    'value' => $jenjang->value,
                    'label' => $jenjang->label(),
                ], Jenjang::cases()),
            ],
        ];
    }

    /**
     * Role yang ditawarkan katalog, ditambah role tenant-wide lain yang kebetulan terpasang
     * (agar tetap terlihat dan bisa dicabut).
     *
     * @return list<array{key: string, name: string, granted: bool, lockedReason: string|null}>
     */
    private function tenantRoles(Membership $membership, ?string $viewerMembershipId): array
    {
        $granted = DB::table('membership_roles')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('membership_roles.tenant_id', $membership->tenant_id)
            ->where('membership_roles.membership_id', $membership->id)
            ->pluck('roles.key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all();

        $keys = array_values(array_unique([...array_keys($this->catalog->tenantRoleOptions()), ...$granted]));
        $isSelf = $membership->id === $viewerMembershipId;
        $roles = [];

        foreach ($keys as $key) {
            $isGranted = in_array($key, $granted, true);

            $roles[] = [
                'key' => $key,
                'name' => $this->catalog->roleName($key),
                'granted' => $isGranted,
                'lockedReason' => $isGranted && $isSelf && $key === CoreAccess::ROLE_ADMIN_YAYASAN
                    ? 'Role Admin Yayasan milik Anda sendiri tidak bisa dicabut.'
                    : null,
            ];
        }

        return $roles;
    }

    /**
     * Penugasan yang masih aktif, beserta nama role-nya.
     *
     * @return list<array{id: string, label: string, functional: bool, roles: list<string>}>
     */
    private function assignments(Membership $membership): array
    {
        $assignments = OrganizationalAssignment::query()
            ->withoutGlobalScope('tenant')
            ->with(['organization' => fn ($query) => $query->withoutGlobalScope('tenant')])
            ->where('tenant_id', $membership->tenant_id)
            ->where('membership_id', $membership->id)
            ->where('status', AssignmentStatus::Active->value)
            ->get();

        $roleNames = DB::table('organizational_assignment_roles')
            ->join('roles', 'roles.id', '=', 'organizational_assignment_roles.role_id')
            ->whereIn('organizational_assignment_roles.organizational_assignment_id', $assignments->pluck('id')->all())
            ->orderBy('roles.name')
            ->get(['organizational_assignment_roles.organizational_assignment_id as assignment_id', 'roles.name']);

        $rows = [];

        foreach ($assignments as $assignment) {
            $rows[] = [
                'id' => $assignment->id,
                'label' => WorkContext::labelFor($assignment->organization?->name, $assignment->jenjang_filter),
                'functional' => $assignment->isFunctional(),
                'roles' => array_values($roleNames
                    ->where('assignment_id', $assignment->id)
                    ->map(fn (object $row): string => (string) ($row->name ?? ''))
                    ->all()),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(string $tenantId): array
    {
        $options = [];

        $organizations = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('code')
            ->get();

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => "{$organization->name} ({$organization->code})"];
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $items  key => nama
     * @return list<array{value: string, label: string}>
     */
    private function toOptions(array $items): array
    {
        $options = [];

        foreach ($items as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
