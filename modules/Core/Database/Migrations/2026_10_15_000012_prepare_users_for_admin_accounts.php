<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun login dibuat admin dari detail pegawai (PRD-000 §4.7 F5a, OD-20/OD-22):
 *
 * - email boleh kosong (banyak pegawai tidak punya email), tetapi akun WAJIB punya
 *   email atau username agar tetap bisa login;
 * - must_change_password = akun memakai kata sandi sementara dan wajib menggantinya
 *   sebelum membuka halaman lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        DB::statement('ALTER TABLE users ADD COLUMN must_change_password boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_login_identifier_check CHECK (email IS NOT NULL OR username IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_login_identifier_check');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS must_change_password');
        // Gagal bila sudah ada akun tanpa email; isi dulu email-nya sebelum rollback.
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};
