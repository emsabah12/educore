<?php

declare(strict_types=1);

namespace Database\Seeders;


use Illuminate\Database\Seeder;
use Modules\Core\Identity\Models\User;

final class DatabaseSeeder extends Seeder
{


    /**
     * Seed the application's database.
     */
    public function run(): void
    {


        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
