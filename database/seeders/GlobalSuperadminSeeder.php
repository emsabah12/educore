<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class GlobalSuperadminSeeder extends Seeder
{
    /**
     * Mengeksekusi penyemaian data Superadmin global secara idempotent selaras dengan skema User Global.
     */
    public function run(): void
    {
        $superadminEmail = 'bsaeful12@gmail.com';

        // Menggunakan Database Transaction demi menjamin keamanan atomisitas data
        DB::transaction(function () use ($superadminEmail) {

            // 1. Cek eksistensi data menggunakan Query Builder mentah berdasarkan email unik
            $existingUser = DB::table('users')
                ->where('email', $superadminEmail)
                ->first();

            // Payload bersih tanpa kolom tenant_id karena relasi diatur oleh tabel membership
            $payload = [
                'name' => 'EduCore Platform Owner',
                'password' => Hash::make('PlatformSecure2026!'),
                'is_superadmin' => true,
                'email_verified_at' => now(),
                'updated_at' => now(),
            ];

            if ($existingUser) {
                // Jika user sudah ada, lakukan update data hak akses
                DB::table('users')
                    ->where('email', $superadminEmail)
                    ->update($payload);

                $this->command->comment('Existing Global Superadmin credentials updated successfully.');
            } else {
                // Jika belum ada, buat rekor data baru dari nol dengan UUID baru
                $payload['id'] = (string) Str::uuid();
                $payload['email'] = $superadminEmail;
                $payload['created_at'] = now();

                DB::table('users')->insert($payload);

                $this->command->info('New Global Superadmin record generated successfully.');
            }
        });

        $this->command->info('Idempotent Global Superadmin seeding operations completed.');
    }
}
