<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen gaji (PRD-001 §16, HR-5a).
 *
 * - `hr_pay_components`: katalog komponen per yayasan. Jenis (PENDAPATAN/POTONGAN) dan cara hitung
 *   (TETAP per bulan / PER_HARI_HADIR) tidak bisa diubah setelah dibuat. Tidak dihapus; dinonaktifkan.
 * - `hr_employee_pay_components`: nominal per pegawai per komponen, rupiah bulat (tanpa sen).
 *   Nominal kosong = baris dihapus (komponen tidak berlaku untuk pegawai itu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_pay_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('kind', 12);
            $table->string('basis', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE hr_pay_components ADD CONSTRAINT hr_pay_components_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,29}$')");
        DB::statement("ALTER TABLE hr_pay_components ADD CONSTRAINT hr_pay_components_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE hr_pay_components ADD CONSTRAINT hr_pay_components_kind_check CHECK (kind IN ('PENDAPATAN', 'POTONGAN'))");
        DB::statement("ALTER TABLE hr_pay_components ADD CONSTRAINT hr_pay_components_basis_check CHECK (basis IN ('TETAP', 'PER_HARI_HADIR'))");

        Schema::create('hr_employee_pay_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('pay_component_id');
            $table->bigInteger('amount');
            $table->uuid('updated_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'pay_component_id']);
            $table->index(['tenant_id', 'pay_component_id']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pay_component_id'])->references(['tenant_id', 'id'])->on('hr_pay_components')->restrictOnDelete();
            $table->foreign(['tenant_id', 'updated_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        // Batas Rp 1 miliar per komponen per bulan: menangkap salah ketik (kelebihan nol).
        DB::statement('ALTER TABLE hr_employee_pay_components ADD CONSTRAINT hr_employee_pay_components_amount_check CHECK (amount BETWEEN 0 AND 1000000000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_pay_components');
        Schema::dropIfExists('hr_pay_components');
    }
};
