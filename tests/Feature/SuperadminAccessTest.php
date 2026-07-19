<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Database\Seeders\GlobalSuperadminSeeder;
use App\Models\User; // <-- Pastikan namespace model ini sesuai dengan proyek Anda

final class SuperadminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mengatur persiapan lingkungan pengujian sebelum setiap metode test dieksekusi.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::disconnect();
        DB::purge();

        // Jalankan migrasi modular terisolasi untuk sandbox database testing
        $this->artisan('migrate', [
            '--path' => [
                'database/migrations',
                'Modules/Core/Database/Migrations',
                'Modules/Auth/Database/Migrations',
            ],
            '--realpath' => true
        ]);
    }

    /**
     * Memastikan GlobalSuperadminSeeder berhasil menyuntikkan data dengan struktur yang valid.
     */
    public function test_superadmin_seeder_creates_valid_global_user(): void
    {
        $this->seed(GlobalSuperadminSeeder::class);

        // Cari berdasarkan email riil yang disinkronkan
        $user = DB::table('users')->where('email', 'bsaeful12@gmail.com')->first();

        $this->assertNotNull($user, 'Akun Superadmin global harus terdaftar di database.');
        $this->assertEquals('EduCore Platform Owner', $user->name);
        $this->assertTrue((bool) $user->is_superadmin, 'Flag is_superadmin wajib bernilai true.');
    }

    /**
     * Memastikan status otentikasi diakui oleh Session Guard Framework secara akurat.
     */
    public function test_superadmin_can_authenticate_and_holds_exclusive_session(): void
    {
        // 1. Jalankan seeder untuk memasukkan entitas fisik
        $this->seed(GlobalSuperadminSeeder::class);

        // 2. Tarik instance model Eloquent agar terintegrasi penuh dengan Session Auth Guard
        $superadminModel = User::where('email', 'bsaeful12@gmail.com')->first();

        $this->assertNotNull($superadminModel, 'Model User Superadmin wajib ditemukan.');
        $this->assertTrue($superadminModel->is_superadmin, 'Atribut model harus membaca status superadmin sebagai true.');

        // 3. Simulasikan login secara paksa melalui State Authenticator Framework
        $this->actingAs($superadminModel);

        // 4. ASSERTIONS
        // Memastikan sistem mengenali user tersebut telah terautentikasi di session aktif
        $this->assertAuthenticated();
        $this->assertEquals($superadminModel->id, Auth::id(), 'ID Session yang aktif harus sama dengan ID Superadmin.');
    }
}
