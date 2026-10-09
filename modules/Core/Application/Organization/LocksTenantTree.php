<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Support\Str;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\Tenant;

/**
 * Bantuan bersama untuk service yang mengubah pohon lembaga.
 */
trait LocksTenantTree
{
    /**
     * Kunci baris tenant selama transaksi berjalan.
     *
     * Perubahan pohon jarang terjadi, jadi cukup diantrekan per tenant. Ini mencegah
     * dua pemindahan bersamaan yang masing-masing valid tetapi bersama-sama
     * menghasilkan siklus atau melebihi kedalaman.
     */
    private function lockTenant(string $tenantId): Tenant
    {
        // ID yang bukan UUID akan membuat PostgreSQL melempar error SQL;
        // anggap saja tidak ditemukan supaya pengguna mendapat pesan yang ramah.
        if (! Str::isUuid($tenantId)) {
            throw OrganizationTreeException::tenantNotFound();
        }

        $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->first();

        if ($tenant === null) {
            throw OrganizationTreeException::tenantNotFound();
        }

        return $tenant;
    }

    /**
     * Cari node HANYA di tenant yang diminta.
     */
    private function findInTenant(string $tenantId, string $organizationId): ?Organization
    {
        if (! Str::isUuid($organizationId)) {
            return null;
        }

        return Organization::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($organizationId)
            ->first();
    }
}
