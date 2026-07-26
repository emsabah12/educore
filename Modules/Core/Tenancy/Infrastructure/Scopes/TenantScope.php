<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Infrastructure\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    /**
     * Terapkan filter scope global pada Builder Eloquent yang diberikan.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Mengecek secara defensif apakah konteks tenant sudah terikat di Service Container
        if (app()->bound('current_tenant_uuid')) {
            $tenantUuid = app('current_tenant_uuid');

            // Suntikkan klausa filter dengan menyertakan nama tabel untuk menghindari ambiguitas kolom
            $builder->where($model->getTable() . '.tenant_id', '=', $tenantUuid);
        }
    }
}
