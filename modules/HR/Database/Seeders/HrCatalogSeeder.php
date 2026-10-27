<?php

namespace Modules\HR\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Catalog\CreateCatalogItem;
use Modules\HR\Application\Catalog\DefaultCatalog;
use Modules\HR\Domain\Catalog\CatalogKind;

/**
 * Isi awal katalog SDM untuk setiap yayasan (PRD-001 §4.3).
 *
 * Aman dijalankan berulang dan di production: hanya menambah kode yang BELUM ada.
 * Item yang sudah diubah namanya atau dinonaktifkan Admin SDM tidak disentuh.
 */
class HrCatalogSeeder extends Seeder
{
    public function __construct(
        private readonly CreateCatalogItem $createCatalogItem,
    ) {}

    public function run(): void
    {
        $tenantIds = Tenant::query()->orderBy('code')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            foreach (DefaultCatalog::items() as $kindValue => $items) {
                $kind = CatalogKind::from($kindValue);

                $existing = $kind->query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->pluck('code')
                    ->all();

                foreach ($items as $code => $name) {
                    if (! in_array($code, $existing, true)) {
                        $flag = in_array($code, DefaultCatalog::FLAGGED[$kind->value] ?? [], true);
                        $this->createCatalogItem->handle((string) $tenantId, $kind, $code, $name, $flag);
                    }
                }
            }
        }
    }
}
