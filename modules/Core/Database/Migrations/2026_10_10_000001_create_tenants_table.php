<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant = yayasan/pelanggan. Batas keamanan dan isolasi data teratas (PRD-000 §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        // Penjaga terakhir di level database: nilai di luar daftar ditolak
        // walaupun ada bug di kode aplikasi.
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('ACTIVE', 'SUSPENDED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
