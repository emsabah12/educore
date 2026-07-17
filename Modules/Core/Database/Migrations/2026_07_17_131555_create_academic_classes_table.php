<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan pembuatan tabel academic_classes.
     */
    public function up(): void
    {
        Schema::create('academic_classes', function (Blueprint $table) {
            // Menggunakan UUIDv7 sebagai primary key sortable secara kronologis
            $table->uuid('id')->primary();

            // Jangkar Konteks Multi-Tenancy
            $table->uuid('tenant_id')->index();

            // Atribut Bisnis Kelas
            $table->string('name', 100); // Contoh: "Kelas X-A", "Kelas VII Belajar"
            $table->string('code', 50)->nullable(); // Kode internal kelas, misal: "K10A"
            $table->string('tingkat', 20); // Contoh: "7", "8", "10", "11"

            // Audit Jejak Waktu & Status
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes()->index(); // Proteksi data historis transaksional nilai

            // Gabungan unik: Dalam satu sekolah tidak boleh ada nama kelas yang sama persis
            $table->unique(['tenant_id', 'name', 'deleted_at']);

            // Foreign Key Constraint
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_classes');
    }
};
