<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi untuk menambahkan isolasi tenant_id pada tabel users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $blueprint) {
            // Tambahkan kolom tenant_id setelah kolom ID utama (jika menggunakan id bawaan laravel)
            // Kolom dibuat nullable terlebih dahulu agar tidak error jika ada data user global/sebelumnya.
            $blueprint->string('tenant_id', 36)->nullable()->after('id');
            
            // Definisi Foreign Key Constraint mengarah ke tabel tenants
            $blueprint->foreign('tenant_id')
                      ->references('id')
                      ->on('tenants')
                      ->onDelete('restrict'); // Mencegah penghapusan tenant jika masih ada user di dalamnya
            
            // Gabungan unique index: Memastikan email hanya boleh unik dalam SATU TENANT yang sama.
            // Di tenant A email admin@mail.com bisa ada, di tenant B juga bisa ada.
            if (Schema::hasColumn('users', 'email')) {
                // Hapus unik bawaan laravel lama terlebih dahulu agar tidak konflik
                // Nama indeks bawaan biasanya 'users_email_unique'
                try {
                    $blueprint->dropUnique('users_email_unique');
                } catch (\Exception $exception) {
                    // Abaikan jika indeks bawaan menggunakan nama berbeda atau belum ada
                }
            }
            
            $blueprint->unique(['tenant_id', 'email']);
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['tenant_id']);
            $blueprint->dropColumn('tenant_id');
            
            // Kembalikan unique constraint email bawaan tunggal laravel jika dibutuhkan
            $blueprint->unique('email');
        });
    }
};