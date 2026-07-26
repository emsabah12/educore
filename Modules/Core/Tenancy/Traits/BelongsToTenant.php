<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Traits;

use Modules\Core\Tenancy\Infrastructure\Scopes\TenantScope;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Exception;

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
            $tenantId = null;

            // Coba dapatkan konteks melalui interface bawaan secara aman
            if (app()->bound(TenantContextInterface::class)) {
                $tenantId = app(TenantContextInterface::class)->getCurrentTenantId();
            }

            // Fallback: Jika kosong, coba ambil langsung dari container UUID yang diikat middleware/test
            if ($tenantId === null && app()->bound('current_tenant_uuid')) {
                $tenantId = app('current_tenant_uuid');
            }

            // DEFENSIVE GUARD: Jika data dicoba ditulis tanpa konteks tenant yang jelas
            if ($tenantId === null) {
                throw new Exception('Bypass Blocked: Cannot write tenant data without an authenticated tenant context.');
            }

            // Isi kolom tenant_id secara otomatis jika belum diisi manual
            if (empty($model->tenant_id)) {
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
