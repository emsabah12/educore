<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Jembatan Konteks Multi-Tenant & Identitas
            $table->uuid('tenant_id')->index(); // Kompatibel dengan master tenants (string 36)
            $table->uuid('membership_id')->unique()->index(); // Dikunci unik 1-to-1 ke peran keanggotaan

            // Atribut Bisnis Spesifik Pegawai
            $table->string('nip', 50)->nullable()->comment('Nomor Induk Pegawai');
            $table->string('jabatan', 100)->default('STAFF'); // GURU, KEPALA_SEKOLAH, STAFF

            $table->timestamps();

            // Foreign Key Constraints
            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Employees');
    }
};
