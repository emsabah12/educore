<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penempatan pegawai: "Jabatan X di Lembaga Y" (PRD-001 §4.3).
 *
 * - organization_id = node pemilik data, dipakai untuk penyaringan per pohon lembaga (§5.3).
 * - Penempatan HR TERPISAH dari penugasan Core (hak akses login). Jabatan bukan hak akses.
 * - Satu pegawai boleh punya beberapa penempatan terbuka; maksimal satu yang utama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('organization_id');
            $table->uuid('position_id');
            $table->boolean('is_primary')->default(false);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'end_date']);
            $table->index(['tenant_id', 'organization_id', 'end_date']);
            $table->index(['tenant_id', 'position_id']);

            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'position_id'])
                ->references(['tenant_id', 'id'])->on('hr_positions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE hr_placements ADD CONSTRAINT hr_placements_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
        // Maksimal satu penempatan utama yang masih berjalan per pegawai.
        DB::statement('CREATE UNIQUE INDEX hr_placements_one_open_primary_unique ON hr_placements (employee_id) WHERE is_primary AND end_date IS NULL');
        // Jabatan yang sama di lembaga yang sama tidak boleh dobel selama masih berjalan.
        DB::statement('CREATE UNIQUE INDEX hr_placements_open_unique ON hr_placements (employee_id, organization_id, position_id) WHERE end_date IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_placements');
    }
};
