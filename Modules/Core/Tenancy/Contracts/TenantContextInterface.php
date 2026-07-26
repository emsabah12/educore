<?php

namespace Modules\Core\Tenancy\Contracts;

use Modules\Core\Tenancy\Models\Tenant;

interface TenantContextInterface
{
    /**
     * Set tenant yang sedang aktif dalam lifecycle saat ini.
     */
    public function setCurrentTenant(Tenant $tenant): void;

    /**
     * Ambil entitas tenant yang sedang aktif saat ini.
     */
    public function getCurrentTenant(): ?Tenant;

    /**
     * Ambil UUID v7 dari tenant yang aktif saat ini.
     */
    public function getCurrentTenantId(): ?string;

    /**
     * Bersihkan status context (reset state).
     */
    public function clear(): void;
}
