<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Infrastructure;

use Illuminate\Support\Str;
use Modules\Core\Tenancy\Contracts\TenantRuntimeResolverInterface;
use Modules\Core\Tenancy\Models\Tenant;

final class EloquentTenantRuntimeResolver implements TenantRuntimeResolverInterface
{
    public function findActiveById(
        string $tenantId,
    ): ?Tenant {
        $tenantId = trim($tenantId);

        if (
            $tenantId === ''
            || ! Str::isUuid($tenantId)
        ) {
            return null;
        }

        return Tenant::query()
            ->whereKey($tenantId)
            ->where('is_active', true)
            ->first();
    }
}
