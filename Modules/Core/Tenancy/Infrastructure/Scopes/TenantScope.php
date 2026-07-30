<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Infrastructure\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Exceptions\TenantContextNotResolvedException;

final class TenantScope implements Scope
{
    /**
     * Terapkan tenant isolation pada query Eloquent.
     *
     * Tenant context merupakan single source of truth.
     *
     * Jika tenant context belum tersedia, operasi tenant-scoped
     * harus dihentikan untuk mencegah data leakage lintas tenant.
     */
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContextInterface $tenantContext */
        $tenantContext = app(TenantContextInterface::class);

        $tenantId = $tenantContext->getCurrentTenantId();


        if ($tenantId === null) {
            throw new TenantContextNotResolvedException();
        }

        $builder->where(
            $model->getTable() . '.tenant_id',
            '=',
            $tenantId
        );
    }
}
