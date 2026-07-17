<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            // 1. Primary Key berbasis Native UUID v7
            $table->uuid('id')->primary();

            // 2. Foreign Keys Berbasis UUID Kompatibel
            $table->uuid('user_id')->index();
            $table->uuid('tenant_id')->index();

            // 3. Otorisasi Kontekstual & Status Kontrol
            // Menggunakan string/enum fleksibel untuk membedakan Aktor lintas lembaga
            $table->string('role', 50)->index(); // PEGAWAI, SANTRI, WALISANTRI, DLL
            $table->string('status', 20)->default('ACTIVE')->index(); // ACTIVE, INACTIVE, SUSPENDED

            $table->timestamps();

            // 4. Integrity Constraints (Foreign Keys di tingkat PostgreSQL)
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            // 5. Unique Composite Index untuk mencegah duplikasi peran ganda pada user di tenant yang sama
            $table->unique(['user_id', 'tenant_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
