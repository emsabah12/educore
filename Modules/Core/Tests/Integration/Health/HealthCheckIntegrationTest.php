<?php

namespace Modules\Core\Tests\Integration\Health;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Services\Health\DatabaseHealthChecker;
use Tests\TestCase;

class HealthCheckIntegrationTest extends TestCase
{
    // Memastikan isolasi database testing tetap bersih
    use RefreshDatabase;

    /**
     * Test untuk memastikan service checker berfungsi normal pada koneksi database aktif.
     */
    public function test_database_health_checker_returns_healthy_state_on_active_connection(): void
    {
        // 1. Instansiasi service secara konkret
        $checker = new DatabaseHealthChecker();

        // 2. Eksekusi pengecekan ke database pgsql riil (educore_testing)
        $result = $checker->check();

        // 3. Assertions (Validasi kontrak struktur data dan nilai)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertArrayHasKey('error', $result);
        
        $this->assertEquals('healthy', $result['status']);
        $this->assertIsFloat($result['latency_ms']);
        $this->assertNull($result['error']);
    }

    /**
     * Test untuk memastikan Artisan Command memberikan output CLI yang tepat.
     */
    public function test_kernel_health_check_command_executes_successfully(): void
    {
        // Jalankan artisan command, tegaskan status sukses (exit code 0), 
        // dan pastikan potongan teks utama yang statis terdeteksi dengan aman.
        $this->artisan('kernel:health-check')
            ->assertSuccessful()
            ->expectsOutputToContain('Memulai inspeksi kesehatan infrastruktur EduCore...')
            ->expectsOutputToContain('Database PostgreSQL Connection');
    }
}