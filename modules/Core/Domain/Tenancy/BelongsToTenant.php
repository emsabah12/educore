<?php

namespace Modules\Core\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Filter otomatis ke yayasan aktif untuk model milik tenant (PRD-000 §5, §6).
 *
 * - Bila konteks kerja sudah terbentuk (request web melewati EnsureWorkContext),
 *   setiap query model ini otomatis ditambah `WHERE tenant_id = <yayasan aktif>`.
 * - Bila belum ada konteks (konsol, seeder, halaman login), filter tidak aktif;
 *   kode di sana WAJIB memakai filter tenant_id eksplisit. Filter ini adalah lapisan
 *   pengaman tambahan, bukan pengganti filter eksplisit.
 *
 * Untuk query lintas yayasan yang disengaja, pakai ->withoutGlobalScope('tenant').
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', /** @param Builder<Model> $query */ function (Builder $query): void {
            $context = app(TenantContext::class)->get();

            if ($context !== null) {
                $query->where($query->getModel()->qualifyColumn('tenant_id'), $context->tenantId);
            }
        });
    }
}
