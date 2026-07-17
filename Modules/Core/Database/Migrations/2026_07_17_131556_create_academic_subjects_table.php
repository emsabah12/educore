<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan pembuatan tabel academic_subjects.
     */
    public function up(): void
    {
        Schema::create('academic_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            // Atribut Bisnis Mata Pelajaran
            $table->string('name', 150); // Contoh: "Matematika", "Fiqih", "Bahasa Inggris"
            $table->string('code', 50)->index(); // Contoh: "MTK-01", "FQH-02"
            $table->enum('category', ['NASIONAL', 'MUATAN_LOKAL', 'PESANTREN'])->default('NASIONAL');

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes()->index();

            // Gabungan unik per sekolah untuk kode mata pelajaran
            $table->unique(['tenant_id', 'code', 'deleted_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_subjects');
    }
};
