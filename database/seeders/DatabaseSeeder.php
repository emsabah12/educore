<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Academic\Database\Seeders\AcademicAuthorizationCatalogSeeder;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's canonical authorization baseline and
     * development identity fixture.
     */
    public function run(): void
    {
        $this->call(AuthorizationCatalogSeeder::class);
        $this->call(AcademicAuthorizationCatalogSeeder::class);
        $this->call(HrAuthorizationCatalogSeeder::class);

        $person = PersonModel::factory()->create([
            'name' => 'Test User',
            'given_name' => 'Test',
            'family_name' => 'User',
        ]);

        User::factory()
            ->for($person, 'person')
            ->create([
                'email' => 'test@example.com',
            ]);
    }
}
