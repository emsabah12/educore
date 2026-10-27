<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen gaji "Menurut jabatan" (PRD-001 §16.1, HR-5a-2).
 *
 * - Cara hitung baru `MENURUT_JABATAN`: nominal tidak diisi per pegawai, tetapi dari tarif jabatan.
 * - `hr_pay_position_rates`: tarif per komponen × jabatan, untuk semua lembaga (`organization_id` kosong)
 *   atau lembaga tertentu (didahulukan). Satu tarif per kombinasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hr_pay_components DROP CONSTRAINT hr_pay_components_basis_check');
        DB::statement("ALTER TABLE hr_pay_components ADD CONSTRAINT hr_pay_components_basis_check CHECK (basis IN ('TETAP', 'PER_HARI_HADIR', 'MENURUT_JABATAN'))");

        Schema::create('hr_pay_position_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('pay_component_id');
            $table->uuid('position_id');
            $table->uuid('organization_id')->nullable();
            $table->bigInteger('amount');
            $table->timestamps();

            $table->index(['tenant_id', 'position_id']);

            $table->foreign(['tenant_id', 'pay_component_id'])->references(['tenant_id', 'id'])->on('hr_pay_components')->restrictOnDelete();
            $table->foreign(['tenant_id', 'position_id'])->references(['tenant_id', 'id'])->on('hr_positions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE hr_pay_position_rates ADD CONSTRAINT hr_pay_position_rates_amount_check CHECK (amount BETWEEN 0 AND 1000000000)');
        // Satu tarif "semua lembaga" dan satu tarif per lembaga untuk setiap komponen × jabatan.
        DB::statement('CREATE UNIQUE INDEX hr_pay_position_rates_all_unique ON hr_pay_position_rates (pay_component_id, position_id) WHERE organization_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX hr_pay_position_rates_org_unique ON hr_pay_position_rates (pay_component_id, position_id, organization_id) WHERE organization_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_pay_position_rates');
        DB::statement("DELETE FROM hr_pay_components WHERE basis = 'MENURUT_JABATAN'");
        DB::statement('ALTER TABLE hr_pay_components DROP CONSTRAINT hr_pay_components_basis_check');
        DB::statement("ALTER TABLE hr_pay_components ADD CONSTRAINT hr_pay_components_basis_check CHECK (basis IN ('TETAP', 'PER_HARI_HADIR'))");
    }
};
