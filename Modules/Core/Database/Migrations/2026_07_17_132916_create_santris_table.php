<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan pembuatan / penyelarasan tabel santris.
     */
    public function up(): void
    {
        // Menghapus jika ada sisa skema usang agar blueprint menjadi fresh & clean
        Schema::dropIfExists('santris');

        Schema::create('santris', function (Blueprint $table) {
            // Menggunakan format UUIDv7 sebagai primary key sortable secara kronologis
            $table->uuid('id')->primary();

            // Jembatan Konteks Multi-Tenant & Identitas Global
            $table->uuid('tenant_id')->index();
            $table->uuid('membership_id')->unique()->index(); // Hubungan 1-to-1 ke tabel memberships (Role: SANTRI)

            // Jangkar Relasi Akademik Core (Terikat ke Kelas)
            $table->uuid('class_id')->index();

            // Atribut Bisnis Spesifik Siswa / Santri
            $table->string('nis', 50)->nullable()->comment('Nomor Induk Santri Internal Lembaga');
            $table->string('nisn', 20)->nullable()->comment('Nomor Induk Siswa Nasional (Kemenristek/Kemenag)');

            $table->timestamps();
            $table->softDeletes()->index();

            // Aturan Unik Combo per Sekolah: NIS tidak boleh kembar di sekolah yang sama
            $table->unique(['tenant_id', 'nis', 'deleted_at']);

            // Foreign Key Constraints untuk menjaga integritas data relasional
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('membership_id')->references('id')->on('memberships')->onDelete('cascade');
            $table->foreign('class_id')->references('id')->on('academic_classes')->onDelete('restrict');
            // 'restrict' artinya kelas tidak boleh dihapus jika masih ada siswa aktif di dalamnya!
        });
    }

    /**
     * Membatalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('santris');
    }
};
