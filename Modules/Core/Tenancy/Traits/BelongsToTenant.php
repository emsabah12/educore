<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Exceptions\TenantContextMismatchException;
use Modules\Core\Tenancy\Exceptions\TenantContextNotResolvedException;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantContext = app(TenantContextInterface::class);

            $tenantId = $tenantContext->getCurrentTenantId();

            if ($tenantId === null) {
                return;
            }

            $builder->where(
                $builder->getModel()->qualifyColumn('tenant_id'),
                $tenantId,
            );
        });

        static::creating(function (Model $model): void {
            self::guardTenantWrite($model);
        });

        static::updating(function (Model $model): void {
            self::guardTenantWrite($model);
        });
    }

    protected static function guardTenantWrite(Model $model): void
    {
        $tenantContext = app(TenantContextInterface::class);

        $activeTenantId = $tenantContext->getCurrentTenantId();

        if ($activeTenantId === null) {
            throw new TenantContextNotResolvedException();
        }

        $modelTenantId = $model->getAttribute('tenant_id');

        if ($modelTenantId === null) {
            $model->setAttribute('tenant_id', $activeTenantId);

            return;
        }

        if ($modelTenantId !== $activeTenantId) {
            throw new TenantContextMismatchException(
                modelClass: $model::class,
                activeTenantId: $activeTenantId,
                modelTenantId: $modelTenantId,
            );
        }
    }
}
