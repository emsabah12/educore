<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan pembuatan tabel pivot walisantri_santri.
     */
    public function up(): void
    {
        Schema::create('walisantri_santri', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Pengaman Konteks Multi-Tenancy
            $table->uuid('tenant_id')->index();

            // Kunci Relasi Banyak-ke-Banyak
            $table->uuid('walisantri_id')->index();
            $table->uuid('santri_id')->index();

            // Atribut Tambahan Opsional
            $table->string('hubungan', 50)->default('AYAH')->comment('AYAH, IBU, WALI, dll');

            $table->timestamps();

            // Aturan Unik Keras: Tidak boleh menautkan anak yang sama ke wali yang sama dua kali!
            $table->unique(['tenant_id', 'walisantri_id', 'santri_id']);

            // Foreign Key Constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('walisantri_id')->references('id')->on('walisantris')->onDelete('cascade');
            $table->foreign('santri_id')->references('id')->on('santris')->onDelete('cascade');
        });
    }

    /**
     * Membatalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('walisantri_santri');
    }
};
