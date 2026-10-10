<?php

namespace Tests\Support;

use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Application\Authorization\SyncAccessCatalog;
use Modules\Core\Application\Membership\AssignToOrganization;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Application\Organization\CreateOrganization;
use Modules\Core\Application\Organization\NewOrganizationData;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Organization\OrganizationCategory;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;

/**
 * Pembuat data uji untuk modul Core. Semua lewat service resmi, sehingga
 * data uji selalu mematuhi aturan yang sama dengan data asli.
 */
final class CoreFixtures
{
    public static function tenant(string $code = 'YYS-A', ?string $name = null): Tenant
    {
        return Tenant::query()->create(['code' => $code, 'name' => $name ?? "Yayasan {$code}"]);
    }

    public static function unit(Tenant $tenant, string $code, ?Organization $parent = null, ?string $name = null): Organization
    {
        return app(CreateOrganization::class)->handle(new NewOrganizationData(
            tenantId: $tenant->id,
            parentId: $parent?->id,
            type: OrganizationType::Unit,
            code: $code,
            name: $name ?? "Unit {$code}",
        ));
    }

    public static function lembaga(Tenant $tenant, string $code, Jenjang $jenjang, ?Organization $parent = null, ?string $name = null): Organization
    {
        return app(CreateOrganization::class)->handle(new NewOrganizationData(
            tenantId: $tenant->id,
            parentId: $parent?->id,
            type: OrganizationType::Lembaga,
            code: $code,
            name: $name ?? "{$jenjang->label()} {$code}",
            category: OrganizationCategory::Formal,
            jenjang: $jenjang,
        ));
    }

    public static function member(User $user, Tenant $tenant): Membership
    {
        return app(GrantMembership::class)->handle($user->person_id, $tenant->id);
    }

    public static function assign(Membership $membership, ?Organization $organization, ?Jenjang $jenjangFilter = null): OrganizationalAssignment
    {
        return app(AssignToOrganization::class)->handle($membership, $organization?->id, $jenjangFilter);
    }

    /** Isi katalog role & permission dari kode (OD-11). */
    public static function syncAccess(): void
    {
        app(SyncAccessCatalog::class)->handle();
    }

    /** Pasang role tenant-wide. Katalog wajib sudah disinkronkan. */
    public static function tenantRole(Membership $membership, string $roleKey): Membership
    {
        app(ManageRoles::class)->grantTenantRole($membership, $roleKey);

        return $membership;
    }

    /** Pasang role di sebuah penugasan. Katalog wajib sudah disinkronkan. */
    public static function assignmentRole(OrganizationalAssignment $assignment, string $roleKey): OrganizationalAssignment
    {
        app(ManageRoles::class)->grantAssignmentRole($assignment, $roleKey);

        return $assignment;
    }

    /** Cari node berdasarkan kode di sebuah yayasan. */
    public static function node(Tenant $tenant, string $code): Organization
    {
        return Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('code', $code)
            ->firstOrFail();
    }
}
