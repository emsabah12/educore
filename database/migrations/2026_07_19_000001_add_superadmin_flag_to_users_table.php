<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menjalankan penambahan kolom is_superadmin ke tabel users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Kolom boolean untuk menandai pemilik platform global
            $table->boolean('is_superadmin')
                ->default(false)
                ->after('email');

            // Pastikan kolom tenant_id di tabel users Anda sudah nullable agar Superadmin global bisa login tanpa tenant_id
        });
    }

    /**
     * Membatalkan perubahan jika dilakukan rollback migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_superadmin');
        });
    }
};
