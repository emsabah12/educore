<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi untuk membuat tabel dummy mock_students.
     */
    public function up(): void
    {
        Schema::create('mock_students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index(); // Menyelaraskan dengan tipe data master tenants.id

            $table->string('name');
            $table->string('nisn', 20)->nullable(); // Kolom penentu yang menyebabkan error sebelumnya
            $table->string('status', 20)->default('ACTIVE');

            $table->timestamps();

            // Constraint opsional untuk lingkungan testing terisolasi
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_students');
    }
};
