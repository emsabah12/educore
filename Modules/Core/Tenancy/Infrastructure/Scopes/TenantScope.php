<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Infrastructure\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;

final class TenantScope implements Scope
{
    /**
     * Terapkan filter tenant pada query Eloquent.
     *
     * Tenant aktif diambil dari TenantContextInterface sebagai
     * single source of truth untuk tenant context.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantContext = app(TenantContextInterface::class);

        $tenantId = $tenantContext->getCurrentTenantId();

        /*
         * Jika tidak ada tenant context aktif, jangan menambahkan
         * filter tenant secara parsial.
         *
         * Perilaku fail-safe penuh untuk query tenant-aware akan
         * difinalisasi pada tahap enforcement berikutnya.
         */
        if ($tenantId === null) {
            return;
        }

        $builder->where(
            $model->getTable() . '.tenant_id',
            '=',
            $tenantId
        );
    }
}
