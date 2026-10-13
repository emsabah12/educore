<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Database\Seeders\AccessCatalogSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\HR\Database\Seeders\HrCatalogSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Catatan: trait WithoutModelEvents bawaan starter kit sengaja dilepas,
     * karena audit log (F4) dan modul lain nanti bergantung pada model event.
     */
    public function run(): void
    {
        // Katalog role & permission dari kode (OD-11). Aman di semua lingkungan.
        $this->call(AccessCatalogSeeder::class);

        // Tenant pertama + pohon lembaga (PRD-000 §4.3).
        $this->call(FirstTenantSeeder::class);

        // Isi awal katalog SDM untuk setiap yayasan (PRD-001 §4.3). Aman diulang.
        $this->call(HrCatalogSeeder::class);

        // Akun uji per peran, password "password" — TIDAK PERNAH di production (OD-10).
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevAccountsSeeder::class);
        }
    }
}
