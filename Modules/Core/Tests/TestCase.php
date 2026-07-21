<?php

namespace Modules\Core\Tests;

use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Inisialisasi awal lingkungan pengujian modul Core.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Jalankan migrasi modul Academic secara aman via Artisan di Test Environment
        $academicMigrationPath = base_path('Modules/Academic/Database/Migrations');

        if (file_exists($academicMigrationPath)) {
            $this->artisan('migrate', [
                '--path' => 'Modules/Academic/Database/Migrations',
            ]);
        }
    }
}
