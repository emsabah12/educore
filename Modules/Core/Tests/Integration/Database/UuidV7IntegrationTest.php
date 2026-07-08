<?php

namespace Modules\Core\Tests\Integration\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UuidV7IntegrationTest extends TestCase
{
    // Memastikan isolasi database pengetesan dibersihkan setiap kali test berjalan
    use RefreshDatabase;

    /**
     * Menyiapkan tabel temporary pengetesan sebelum mengeksekusi metode test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Bersihkan skema tabel jika sisa pengujian sebelumnya masih menggantung
        Schema::connection('pgsql')->dropIfExists('test_uuid_models');

        // Buat tabel temporary pengetesan secara eksplisit di dalam driver pgsql
        Schema::connection('pgsql')->create('test_uuid_models', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid_v7')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Menggunakan awalan "test_" secara eksplisit agar pasti terdeteksi oleh engine PHPUnit
     */
    public function test_it_automatically_generates_and_persists_valid_uuid_v7_on_creation(): void
    {
        // 1. Ambil representasi string UUID v7 native dari framework
        $uuidV7 = (string) Str::uuid7();

        // 2. Tulis data secara langsung ke database testing educore_testing
        $insertedId = DB::connection('pgsql')->table('test_uuid_models')->insertGetId([
            'uuid_v7' => $uuidV7,
            'name' => 'EduCore Integration Test v7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assert 1: Pastikan data berhasil masuk dan mengembalikan ID numerik
        $this->assertIsNumeric($insertedId);
        
        // Assert 2: Ambil kembali data segar dari database pgsql
        $model = DB::connection('pgsql')->table('test_uuid_models')->find($insertedId);
        
        // Assert 3: Validasi integritas format dan nilai UUID v7
        $this->assertNotNull($model);
        $this->assertEquals($uuidV7, $model->uuid_v7);
        $this->assertEquals(36, strlen($model->uuid_v7)); // Panjang standar string UUID
    }

    /**
     * Memastikan bahwa properti waktu pada UUID v7 dapat diurutkan secara kronologis (Time-ordered)
     */
    public function test_it_generates_chronologically_sortable_uuids(): void
    {
        $uuid1 = (string) Str::uuid7();
        
        // Beri jeda 2 milidetik agar komponen timestamp internal UUID v7 bergerak maju
        usleep(2000); 
        
        $uuid2 = (string) Str::uuid7();

        // Sesuai spesifikasi RFC 9562, UUID v7 yang dibuat belakangan harus secara leksikografis lebih besar
        $this->assertLessThan($uuid2, $uuid1);
    }

    /**
     * Membersihkan sisa skema tabel setelah rangkaian pengujian selesai dijalankan.
     */
    protected function tearDown(): void
    {
        Schema::connection('pgsql')->dropIfExists('test_uuid_models');
        parent::tearDown();
    }
}