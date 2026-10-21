<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mata pelajaran kelas & guru pengampu (PRD-002 §16 AC-3b).
 *
 * - Kelas dan mapel selalu di lembaga yang sama (FK gabungan tenant + lembaga).
 * - Satu mapel paling banyak sekali per kelas; satu pengampu per mapel per kelas (opsional).
 * - Pengampu = pegawai HR (FK ke hr_employees). Syarat "aktif dan ditempatkan di lembaga itu"
 *   dicek service lewat kontrak publik HR.
 * - Kelas kosong yang dihapus ikut menghapus mapelnya; mapel yang sudah dipakai tidak bisa dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_class_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->uuid('class_id');
            $table->uuid('subject_id');
            $table->uuid('teacher_employee_id')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'subject_id']);
            $table->index(['subject_id']);
            $table->index(['tenant_id', 'teacher_employee_id']);

            $table->foreign(['tenant_id', 'organization_id', 'academic_year_id', 'class_id'])
                ->references(['tenant_id', 'organization_id', 'academic_year_id', 'id'])->on('ac_classes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'subject_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_subjects')->restrictOnDelete();
            // teacher_employee_id kosong → foreign key tidak diperiksa (MATCH SIMPLE).
            $table->foreign(['tenant_id', 'teacher_employee_id'])
                ->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_class_subjects');
    }
};
