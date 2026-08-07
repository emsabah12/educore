<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Contracts;

use Modules\Core\Tenancy\Models\Tenant;

interface TenantRuntimeResolverInterface
{
    /**
     * Resolve tenant aktif yang dapat digunakan sebagai runtime context.
     *
     * Tenant yang tidak ditemukan, sudah soft-deleted, atau tidak aktif
     * selalu menghasilkan null.
     */
    public function findActiveById(
        string $tenantId,
    ): ?Tenant;
}
