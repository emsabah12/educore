<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi untuk membuat tabel master tenants.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $blueprint) {
            // Menggunakan tipe data string 36 karakter untuk UUID v7 Primary Key
            $blueprint->uuid('id')->primary();

            $blueprint->string('name');
            $blueprint->string('subdomain')->unique()->comment('Contoh: sekolah-a');
            $blueprint->string('domain')->nullable()->unique()->comment('Contoh: sekolah-a.sch.id');
            $blueprint->boolean('is_active')->default(true);

            // Kolom JSONB untuk fleksibilitas pengaturan dinamis tiap sekolah (PostgreSQL)
            $blueprint->jsonb('settings')->nullable();

            $blueprint->timestamps();
            $blueprint->softDeletes(); // Audit Trail & Safety untuk penghapusan

            // Indexing tambahan demi optimasi pencarian subdomain/domain di middleware
            $blueprint->index(['subdomain', 'is_active']);
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
