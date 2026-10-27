<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rekap gaji bulanan (PRD-001 §16.3, HR-5c-1).
 *
 * - `hr_payroll_adjustments`: penyesuaian manual per pegawai per bulan (tambahan/potongan + keterangan).
 * - `hr_payroll_months`: satu baris per bulan yang DIKUNCI (tidak ada baris = belum dikunci).
 * - `hr_payslips`: snapshot gaji per pegawai saat bulan dikunci — baris rincian & dasar hitung disalin
 *   agar perubahan tarif/jabatan/jadwal/presensi sesudahnya tidak mengubah bulan itu. Buka kunci =
 *   baris bulan & snapshot dihapus (tercatat di audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->char('month', 7);
            $table->uuid('employee_id');
            $table->string('kind', 12);
            $table->bigInteger('amount');
            $table->string('note', 200);
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'month']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_payroll_adjustments ADD CONSTRAINT hr_payroll_adjustments_month_check CHECK (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')");
        DB::statement("ALTER TABLE hr_payroll_adjustments ADD CONSTRAINT hr_payroll_adjustments_kind_check CHECK (kind IN ('PENDAPATAN', 'POTONGAN'))");
        DB::statement('ALTER TABLE hr_payroll_adjustments ADD CONSTRAINT hr_payroll_adjustments_amount_check CHECK (amount BETWEEN 1 AND 1000000000)');
        DB::statement("ALTER TABLE hr_payroll_adjustments ADD CONSTRAINT hr_payroll_adjustments_note_check CHECK (btrim(note) <> '')");

        Schema::create('hr_payroll_months', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->char('month', 7);
            $table->char('until', 10);
            $table->unsignedInteger('employee_count');
            $table->bigInteger('earning');
            $table->bigInteger('deduction');
            $table->bigInteger('net');
            $table->timestampTz('locked_at');
            $table->uuid('locked_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'month']);
            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'locked_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_payroll_months ADD CONSTRAINT hr_payroll_months_month_check CHECK (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')");

        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('payroll_month_id');
            $table->uuid('employee_id');
            $table->string('employee_name', 200);
            $table->string('employee_number', 30)->nullable();
            $table->uuid('organization_id')->nullable();
            $table->string('organization', 200)->nullable();
            $table->bigInteger('earning');
            $table->bigInteger('deduction');
            $table->bigInteger('net');
            $table->jsonb('lines');
            $table->jsonb('basis');
            $table->timestamps();

            $table->unique(['payroll_month_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id']);

            $table->foreign(['tenant_id', 'payroll_month_id'])->references(['tenant_id', 'id'])->on('hr_payroll_months')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
        Schema::dropIfExists('hr_payroll_months');
        Schema::dropIfExists('hr_payroll_adjustments');
    }
};
