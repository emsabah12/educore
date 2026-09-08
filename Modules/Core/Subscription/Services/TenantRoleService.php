<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Services;

use Modules\Core\Authorization\Models\Role;
use Modules\Core\Subscription\Exceptions\CustomRoleFeatureNotAvailableException;

/**
 * §PRD Subscription & Custom Role — role kustom milik satu tenant
 * (`roles.tenant_id` terisi). Kewenangan MEMBUAT/MENGELOLA role
 * kustom (owner/admin/tim ops tenant) diperiksa lewat permission
 * `tenant.custom-roles.manage` di lapisan HTTP/middleware nanti
 * (Step E) — service ini murni logika domain, TIDAK memeriksa
 * otorisasi pemanggil sendiri, konsisten dengan pola service layer
 * lain di seluruh sesi ini.
 */
final class TenantRoleService
{
    public const CUSTOM_ROLES_FEATURE_CODE = 'custom_roles';

    public function __construct(
        private readonly TenantSubscriptionService $subscriptionService,
    ) {}

    /**
     * @throws CustomRoleFeatureNotAvailableException kalau tenant
     *                                                 sedang tidak
     *                                                 punya fitur
     *                                                 `custom_roles`
     *                                                 efektif.
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
}
