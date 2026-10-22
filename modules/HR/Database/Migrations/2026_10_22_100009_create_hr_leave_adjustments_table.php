<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian jatah cuti (PRD-001 §13.2 & §13.5, HR-3d-b): tambah (+) atau kurangi (−) jatah satu
 * pegawai untuk satu jenis berjatah pada satu tahun. Saldo = jatah + Σ penyesuaian − terpakai.
 * Catatan tidak diubah atau dihapus; koreksi dengan penyesuaian baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->unsignedSmallInteger('year');
            $table->smallInteger('days');
            $table->string('reason', 300);
            $table->uuid('created_by_membership_id');
            $table->timestampTz('created_at');

            $table->index(['employee_id', 'leave_type_id', 'year']);
            $table->index(['tenant_id', 'year']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'leave_type_id'])->references(['tenant_id', 'id'])->on('hr_leave_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE hr_leave_adjustments ADD CONSTRAINT hr_leave_adjustments_days_check CHECK (days <> 0 AND days BETWEEN -366 AND 366)');
        DB::statement('ALTER TABLE hr_leave_adjustments ADD CONSTRAINT hr_leave_adjustments_year_check CHECK (year BETWEEN 2000 AND 2100)');
        DB::statement("ALTER TABLE hr_leave_adjustments ADD CONSTRAINT hr_leave_adjustments_reason_not_blank_check CHECK (btrim(reason) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_adjustments');
    }
};
