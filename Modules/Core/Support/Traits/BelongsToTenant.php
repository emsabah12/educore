<?php

namespace Modules\Core\Support\Traits;

use Modules\Core\Database\Scopes\TenantScope;
use Modules\Core\Contracts\TenantContextInterface;
use Modules\Core\Entities\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

trait BelongsToTenant
{
    /**
     * Boot trait ini secara otomatis untuk model Eloquent yang menggunakannya.
     */
    public static function bootBelongsToTenant(): void
    {
        // 1. Daftarkan TenantScope sebagai Global Scope Query
        static::addGlobalScope(new TenantScope());

        // 2. Intersepsi model event 'creating' untuk mengotomatisasi pengisian tenant_id
        static::creating(function ($model) {
            $tenantContext = app(TenantContextInterface::class);
            $tenantId = $tenantContext->getCurrentTenantId();

            if ($tenantId !== null && empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
                
                Log::debug('Tenant ID automatically injected into model creation.', [
                    'model' => get_class($model),
                    'tenant_id' => $tenantId
                ]);
            }
        });
    }

    /**
     * Definisi Relasi: Setiap entitas tenant-aware terikat ke satu Tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}