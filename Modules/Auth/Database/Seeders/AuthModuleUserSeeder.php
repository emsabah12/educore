<?php

declare(strict_types=1);

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Support\Uuid\UuidV7;

final class AuthModuleUserSeeder extends Seeder
{
    /**
     * Jalankan penyemaian data berantai (Users -> Memberships -> Profiles).
     */
    public function run(): void
    {
        // 1. Definisikan ID Tenant Acuan Tetap (Pusat)
        $tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        // Cek secara defensif apakah Tenant sudah ada di database
        $tenantExists = DB::table('tenants')->where('id', '=', $tenantId)->exists();

        if (!$tenantExists) {
            $this->command->info("Tenant acuan tidak ditemukan. Membuat Tenant baru: {$tenantId}");
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => 'Lembaga Pendidikan Pusat EduCore',
                'subdomain' => 'pusat',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // 2. Buat Akun User Global (Super Administrator)
        $userEmail = 'superadmin@educore.id';
        $userId = UuidV7::generate();

        // Cek apakah user dengan email tersebut sudah ada untuk menghindari duplicate key error
        $userExists = DB::table('users')->where('email', '=', $userEmail)->exists();

        if (!$userExists) {
            DB::table('users')->insert([
                'id' => $userId,
                'name' => 'Super Administrator Global',
                'email' => $userEmail,
                'password' => Hash::make('secretpassword'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $this->command->info("Akun User Global berhasil dibuat. UUID: {$userId}");
        } else {
            // Jika user sudah ada, ambil ID-nya untuk keberlanjutan relasi seeder
            $userId = DB::table('users')->where('email', '=', $userEmail)->value('id');
        }

        // 3. Buat Jembatan Multi-Tenant Membership (Peran: PEGAWAI)
        $membershipId = UuidV7::generate();

        $membershipExists = DB::table('memberships')
            ->where('user_id', '=', $userId)
            ->where('tenant_id', '=', $tenantId)
            ->exists();

        if (!$membershipExists) {
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role' => 'PEGAWAI', // PEGAWAI, SANTRI, WALISANTRI
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->command->info("Jembatan Multi-Tenant Membership berhasil dikaitkan!");
            $this->command->info("-> Membership ID : {$membershipId}");
            $this->command->info("-> Terikat Peran : PEGAWAI pada Tenant ID {$tenantId}");

            // 4. STRATEGI BERANTAI: Suntikkan Profil Bisnis Berdasarkan Peran (Role)
            // Karena perannya PEGAWAI, maka wajib membuat record di tabel pegawais
            DB::table('pegawais')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId, // Redundansi terencana untuk efisiensi kueri tanpa JOIN
                'membership_id' => $membershipId,
                'nip' => '19982026121001',
                'jabatan' => 'SUPER_ADMINISTRATOR',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->command->info("✓ Profil Bisnis PEGAWAI berhasil disemai ke PostgreSQL.");
        }
    }
}
