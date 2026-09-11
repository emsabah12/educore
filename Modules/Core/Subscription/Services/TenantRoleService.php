<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Subscription\Exceptions\CustomRoleFeatureNotAvailableException;

/**
 * §PRD Subscription & Custom Role — role kustom milik satu tenant
 * (`roles.tenant_id` terisi). Kewenangan MEMBUAT/MENGELOLA role
 * kustom (owner/admin/tim ops tenant) diperiksa lewat permission
 * `tenant.custom-roles.manage` di lapisan HTTP/middleware (Step E) —
 * service ini murni logika domain, TIDAK memeriksa otorisasi
 * pemanggil sendiri, konsisten dengan pola service layer lain di
 * seluruh sesi ini.
 */
final class TenantRoleService
{
    public const CUSTOM_ROLES_FEATURE_CODE = 'custom_roles';

    public const VISIBILITY_ACTIVE = 'active';

    public const VISIBILITY_LOCKED_READONLY = 'locked_readonly';

    public const VISIBILITY_LOCKED_HIDDEN = 'locked_hidden';

    public function __construct(
        private readonly TenantSubscriptionService $subscriptionService,
    ) {}

    /**
     * @throws CustomRoleFeatureNotAvailableException kalau tenant
     *                                                sedang tidak
     *                                                punya fitur
     *                                                `custom_roles`
     *                                                efektif.
     */
    public function createCustomRole(
        string $tenantId,
        string $name,
        string $displayName,
        ?string $description = null,
    ): Role {
        if (! $this->isCustomRoleFeatureEffective($tenantId)) {
            throw new CustomRoleFeatureNotAvailableException($tenantId);
        }

        return Role::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'display_name' => $displayName,
            'description' => $description,
        ]);
    }

    /**
     * Semua role kustom milik SATU tenant — tidak pernah menyaring
     * berdasar `visibilityState()`, sengaja mengembalikan SEMUA
     * (termasuk yang `locked_hidden`) supaya lapisan presentasi
     * (frontend) yang memutuskan cara menampilkannya, bukan backend
     * yang diam-diam membuang data.
     */
    public function listCustomRoles(string $tenantId): Collection
    {
        return Role::query()
            ->where('tenant_id', $tenantId)
            ->withCount('permissions')
            ->orderBy('name')
            ->get();
    }

    /**
     * Katalog permission yang BOLEH dipilih tenant untuk role kustom
     * mereka.
     *
     * §Keputusan cakupan (MVP): SEMUA permission di katalog global,
     * TANPA dibatasi entitlement modul/paket tenant — ini SATU-
     * SATUNYA tempat aturan itu diterapkan. Kalau nanti aturan
     * berubah jadi "hanya permission dari modul yang benar-benar
     * tenant miliki", cukup ubah isi method ini (mis. filter
     * berdasar `permissions.module` dicocokkan ke fitur aktif
     * tenant) — controller, route, dan frontend TIDAK PERLU berubah
     * sama sekali karena mereka cuma memanggil method ini.
     */
    public function assignablePermissions(string $tenantId): Collection
    {
        return Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get();
    }

    public function syncPermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);
    }

    /**
     * Apakah fitur `custom_roles` efektif untuk tenant ini SEKARANG
     * (lewat paket ATAU add-on `trial`/`active`) — dipakai untuk
     * memutuskan boleh/tidaknya MEMBUAT role kustom baru.
     */
    public function isCustomRoleFeatureEffective(string $tenantId): bool
    {
        return in_array(
            self::CUSTOM_ROLES_FEATURE_CODE,
            $this->subscriptionService->effectiveFeatureCodes($tenantId),
            true,
        );
    }

    /**
     * Apakah SATU role tertentu sedang memberi akses efektif — role
     * sistem/global (`tenant_id === null`) SELALU efektif; role
     * kustom mengikuti status fitur `custom_roles` tenant pemiliknya
     * SAAT INI (bisa berubah kapan saja tanpa perlu migrasi data,
     * dihitung on-the-fly — lihat `AuthorizationService::hasPermission()`
     * untuk titik penegakan sesungguhnya).
     */
    public function isRoleEffective(Role $role): bool
    {
        if ($role->tenant_id === null) {
            return true;
        }

        return $this->isCustomRoleFeatureEffective($role->tenant_id);
    }

    /**
     * §PRD: "efeknya akan menjadi read-only ... sampai nanti akan
     * menjadi hide/blank tetapi tidak hilang" — tahap penguncian yang
     * LEBIH RINCI dari `isRoleEffective()` (yang cuma tahu ya/tidak).
     * Dipakai UI untuk memutuskan: tampilkan biasa, tampilkan
     * abu-abu/read-only, atau sembunyikan sepenuhnya.
     *
     * Kalau fitur hilang lewat DOWNGRADE PAKET (bukan pencabutan
     * add-on), TIDAK ADA baris `tenant_addons` untuk diperiksa —
     * sesuai keputusan PRD ("ganti PAKET berefek LANGSUNG tanpa masa
     * tenggang"), kasus ini langsung dianggap `locked_hidden`.
     */
    public function visibilityStateFor(Role $role): string
    {
        if ($role->tenant_id === null || $this->isRoleEffective($role)) {
            return self::VISIBILITY_ACTIVE;
        }

        $addonStatus = $this->subscriptionService->addonStatusForFeature(
            $role->tenant_id,
            self::CUSTOM_ROLES_FEATURE_CODE,
        );

        return $addonStatus === 'locked_readonly'
            ? self::VISIBILITY_LOCKED_READONLY
            : self::VISIBILITY_LOCKED_HIDDEN;
    }
}
