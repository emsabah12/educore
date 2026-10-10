<?php

namespace Modules\Core\Application\Context;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\AssignmentStatus;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\TenantStatus;
use Modules\Core\Domain\Tenancy\WorkContext;

/**
 * Menentukan konteks kerja (yayasan + lembaga) untuk pengguna yang login (PRD-000 §6).
 *
 * Pilihan di session tidak pernah dipercaya begitu saja: setiap request dicek ulang
 * apakah membership, yayasan, penugasan, dan lembaganya masih aktif dan memang
 * milik orang ini. Semua query di sini sengaja lintas filter tenant otomatis karena
 * konteks justru sedang ditentukan.
 */
final class WorkContextResolver
{
    public const ROUTE_UNREGISTERED = 'context.unregistered';

    public const ROUTE_PLATFORM = 'platform.home';

    public const ROUTE_SELECT_TENANT = 'context.tenant.edit';

    public const ROUTE_SELECT_WORKSPACE = 'context.workspace.edit';

    public function resolve(User $user, Session $session): ContextResolution
    {
        $memberships = $this->memberships($user);

        if ($memberships->isEmpty()) {
            $this->forget($session);

            return ContextResolution::redirectTo($user->is_superadmin ? self::ROUTE_PLATFORM : self::ROUTE_UNREGISTERED);
        }

        $membership = $memberships->firstWhere('id', $session->get(WorkContextSession::MEMBERSHIP));

        if (! $membership instanceof Membership) {
            $session->forget(WorkContextSession::WORKSPACE);

            if ($memberships->count() > 1) {
                $session->forget(WorkContextSession::MEMBERSHIP);

                return ContextResolution::redirectTo(self::ROUTE_SELECT_TENANT);
            }

            // Hanya satu yayasan → langsung dipilih (PRD-000 §6).
            /** @var Membership $membership */
            $membership = $memberships->first();
            $session->put(WorkContextSession::MEMBERSHIP, $membership->id);
        }

        $assignments = $this->assignments($membership);
        $offersTenant = $this->offersTenantWorkspace($membership, $assignments);
        $optionCount = $assignments->count() + ($offersTenant ? 1 : 0);
        $workspace = $session->get(WorkContextSession::WORKSPACE);
        $assignment = $assignments->firstWhere('id', $workspace);

        if (! $assignment instanceof OrganizationalAssignment) {
            $assignment = null;

            if (! ($workspace === WorkContext::WORKSPACE_TENANT && $offersTenant)) {
                if ($optionCount > 1) {
                    $session->forget(WorkContextSession::WORKSPACE);

                    return ContextResolution::redirectTo(self::ROUTE_SELECT_WORKSPACE);
                }

                // Hanya satu pilihan → langsung dipilih (OD-08). Tanpa penugasan → Seluruh Yayasan (OD-09).
                if ($offersTenant) {
                    $session->put(WorkContextSession::WORKSPACE, WorkContext::WORKSPACE_TENANT);
                } else {
                    /** @var OrganizationalAssignment $assignment */
                    $assignment = $assignments->first();
                    $session->put(WorkContextSession::WORKSPACE, $assignment->id);
                }
            }
        }

        return ContextResolution::resolved(new WorkContext(
            tenantId: $membership->tenant_id,
            tenantName: $membership->tenant->name,
            membershipId: $membership->id,
            assignmentId: $assignment?->id,
            organizationId: $assignment?->organization_id,
            organizationName: $assignment?->organization?->name,
            jenjangFilter: $assignment?->jenjang_filter,
            canSwitchTenant: $memberships->count() > 1,
            canSwitchWorkspace: $optionCount > 1,
        ));
    }

    /**
     * Membership aktif milik orang ini, hanya di yayasan yang aktif, urut nama yayasan.
     *
     * @return Collection<int, Membership>
     */
    public function memberships(User $user): Collection
    {
        return Membership::query()
            ->where('person_id', $user->person_id)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('tenant', fn ($tenant) => $tenant->where('status', TenantStatus::Active->value))
            ->with('tenant')
            ->get()
            ->sortBy(fn (Membership $membership): string => $membership->tenant->name)
            ->values();
    }

    /**
     * Penugasan aktif sebuah membership yang lembaganya (bila ada) juga masih aktif.
     *
     * @return Collection<int, OrganizationalAssignment>
     */
    public function assignments(Membership $membership): Collection
    {
        return OrganizationalAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $membership->tenant_id)
            ->where('membership_id', $membership->id)
            ->where('status', AssignmentStatus::Active->value)
            ->where(fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhereHas('organization', fn ($organization) => $organization
                    ->withoutGlobalScope('tenant')
                    ->where('status', OrganizationStatus::Active->value)))
            ->with(['organization' => fn ($organization) => $organization->withoutGlobalScope('tenant')])
            ->get()
            ->sortBy(fn (OrganizationalAssignment $assignment): string => WorkContext::labelFor($assignment->organization?->name, $assignment->jenjang_filter))
            ->values();
    }

    /**
     * Apakah "Seluruh Yayasan" termasuk pilihan lembaga kerja (PRD-000 §6)?
     * Ya bila anggota punya role tenant-wide, atau tidak punya penugasan sama sekali (OD-09).
     *
     * @param  Collection<int, OrganizationalAssignment>  $assignments  hasil assignments($membership)
     */
    public function offersTenantWorkspace(Membership $membership, Collection $assignments): bool
    {
        if ($assignments->isEmpty()) {
            return true;
        }

        return DB::table('membership_roles')
            ->where('tenant_id', $membership->tenant_id)
            ->where('membership_id', $membership->id)
            ->exists();
    }

    /**
     * Membership yang sedang dipilih di session, bila masih sah.
     */
    public function currentMembership(User $user, Session $session): ?Membership
    {
        $membership = $this->memberships($user)->firstWhere('id', $session->get(WorkContextSession::MEMBERSHIP));

        return $membership instanceof Membership ? $membership : null;
    }

    public function forget(Session $session): void
    {
        $session->forget([WorkContextSession::MEMBERSHIP, WorkContextSession::WORKSPACE]);
    }

    /**
     * Nama-nama induk sebuah node, dari atas ke bawah, mis. "Pondok Pesantren › Unit 1".
     * Dipakai sebagai keterangan di halaman pilih lembaga kerja.
     */
    public function pathOf(Organization $organization): ?string
    {
        if ($organization->parent_id === null) {
            return null;
        }

        $ancestors = Organization::query()
            ->withoutGlobalScope('tenant')
            ->join('organization_closure', 'organization_closure.ancestor_id', '=', 'organizations.id')
            ->where('organization_closure.tenant_id', $organization->tenant_id)
            ->where('organization_closure.descendant_id', $organization->id)
            ->where('organization_closure.depth', '>', 0)
            ->orderByDesc('organization_closure.depth')
            ->pluck('organizations.name');

        return $ancestors->implode(' › ');
    }
}
