<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Application\Authorization\SyncAccessCatalog;

/**
 * Isi katalog role & permission dari kode (OD-11). Aman di semua lingkungan dan aman diulang.
 * Sama dengan `php artisan educore:sync-access`.
 */
class AccessCatalogSeeder extends Seeder
{
    public function __construct(
        private readonly SyncAccessCatalog $sync,
    ) {}

    public function run(): void
    {
        $this->sync->handle();
    }
}
