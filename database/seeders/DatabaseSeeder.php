<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Person;

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
        // Tenant pertama + pohon lembaga (PRD-000 §4.3).
        $this->call(FirstTenantSeeder::class);

        // Akun uji development (password: "password"). Akun per peran dibuat di F2b.
        if (! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()
                ->for(Person::factory()->state(['name' => 'Pengguna Uji']))
                ->create([
                    'email' => 'test@example.com',
                    'username' => 'penguji',
                ]);
        }
    }
}
