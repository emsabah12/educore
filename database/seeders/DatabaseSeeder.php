<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;

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

        // Akun uji bawaan starter kit. Akan diganti saat F2 (Person + User + Membership).
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
