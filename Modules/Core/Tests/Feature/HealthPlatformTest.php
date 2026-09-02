<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

final class HealthPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(\Modules\Core\Providers\RouteServiceProvider::class)) {
            $this->app->register(\Modules\Core\Providers\RouteServiceProvider::class);
        }
    }

    /**
     * Skenario Sukses: Memastikan kondisi dasar server berjalan normal (200 OK)
     */
    public function test_health_check_returns_200_when_system_is_healthy(): void
    {
        // Pastikan koneksi dibersihkan ke kondisi awal yang sehat sebelum berjalan
        DB::disconnect();
        DB::purge();

        $url = '/api/v1/core/health';
        $response = $this->withHeaders(['Accept' => 'application/json'])->json('GET', $url);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'UP');
    }

    /**
     * Skenario Gagal: Memastikan fault tolerance berjalan lancar saat database terganggu (503)
     */
    public function test_health_check_returns_503_when_database_fails(): void
    {
        $url = '/api/v1/core/health';

        DB::disconnect();
        $defaultConnection = DB::getDefaultConnection();

        // Simpan nilai asli koneksi untuk dipulihkan nanti agar tidak merusak pengujian lain
        $originalDatabase = config("database.connections.{$defaultConnection}.database");
        $originalUsername = config("database.connections.{$defaultConnection}.username");

        // Suntikkan kegagalan otentikasi kredensial
        Config::set("database.connections.{$defaultConnection}.database", 'db_palsu_testing');
        Config::set("database.connections.{$defaultConnection}.username", 'user_salah_diagnostik');
        DB::purge($defaultConnection);

        $response = $this->withHeaders(['Accept' => 'application/json'])->json('GET', $url);

        // RESTORE STATE: Kembalikan konfigurasi asli setelah request selesai
        Config::set("database.connections.{$defaultConnection}.database", $originalDatabase);
        Config::set("database.connections.{$defaultConnection}.username", $originalUsername);
        DB::disconnect();
        DB::purge($defaultConnection);

        // Kunci verifikasi status manajemen DevOps
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'DOWN');
        $response->assertJsonPath('components.database.healthy', false);
    }

    /**
     * GAP-024: Endpoint ini publik (tidak ada middleware auth). Detail
     * koneksi internal (nama database, username) yang disuntikkan salah
     * di atas TIDAK BOLEH pernah muncul di response — hanya boleh masuk
     * log operasional.
     */
    public function test_health_check_response_never_exposes_raw_connection_details(): void
    {
        $url = '/api/v1/core/health';

        DB::disconnect();
        $defaultConnection = DB::getDefaultConnection();

        $originalDatabase = config("database.connections.{$defaultConnection}.database");
        $originalUsername = config("database.connections.{$defaultConnection}.username");

        Config::set("database.connections.{$defaultConnection}.database", 'db_palsu_testing');
        Config::set("database.connections.{$defaultConnection}.username", 'user_salah_diagnostik');
        DB::purge($defaultConnection);

        $response = $this->withHeaders(['Accept' => 'application/json'])->json('GET', $url);

        Config::set("database.connections.{$defaultConnection}.database", $originalDatabase);
        Config::set("database.connections.{$defaultConnection}.username", $originalUsername);
        DB::disconnect();
        DB::purge($defaultConnection);

        $rawBody = $response->getContent();

        $this->assertIsString($rawBody);
        $this->assertStringNotContainsString('db_palsu_testing', $rawBody);
        $this->assertStringNotContainsString('user_salah_diagnostik', $rawBody);

        $response->assertJsonPath(
            'components.database.message',
            'Database connectivity check failed.',
        );
    }
}
