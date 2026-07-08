<?php

namespace Modules\Core\Database\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Contracts\TenantContextInterface;
use Illuminate\Support\Facades\Log;

class TenantScope implements Scope
{
    /**
     * Terapkan scope filter tenant ke Eloquent Query Builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Resolve Tenant Context dari IoC Container
        $tenantContext = app(TenantContextInterface::class);
        $tenantId = $tenantContext->getCurrentTenantId();

        // Scope hanya berjalan jika tenant_id sudah terikat di Context (Request via Subdomain)
        // Ini memberi fleksibilitas jika diakses via Central Console / Seeder Global
        if ($tenantId !== null) {
            $builder->where($model->getTable() . '.tenant_id', $tenantId);
        } else {
            Log::debug('TenantScope skipped: No active tenant bound to context.', [
                'model' => get_class($model)
            ]);
        }
    }
}