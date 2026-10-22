<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lembar nilai (PRD-002 §19 AC-5b).
 *
 * - Lembar = mapel kelas × semester (satu lembar). Menyimpan salinan aturan penilaian (`rules`:
 *   KKM, batas predikat, komponen & bobot) saat pertama kali disimpan, status DRAFT/FINAL.
 * - Nilai = lembar × pendaftaran siswa: nilai per kode komponen (`scores`), nilai akhir (bulat,
 *   kosong bila ada komponen kosong), deskripsi.
 * - Mapel kelas dan semester yang sudah punya lembar tidak bisa dihapus (restrict); service memberi
 *   pesan yang jelas lebih dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_grade_sheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->uuid('class_id');
            $table->uuid('class_subject_id');
            $table->uuid('term_id');
            $table->string('status', 10)->default('DRAFT');
            $table->jsonb('rules');
            $table->uuid('finalized_by_membership_id')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['class_subject_id', 'term_id']);
            $table->unique(['tenant_id', 'organization_id', 'id']);
            $table->index(['class_id', 'term_id']);

            $table->foreign(['tenant_id', 'organization_id', 'academic_year_id', 'class_id'])
                ->references(['tenant_id', 'organization_id', 'academic_year_id', 'id'])->on('ac_classes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'class_id', 'class_subject_id'])
                ->references(['tenant_id', 'class_id', 'id'])->on('ac_class_subjects')->restrictOnDelete();
            $table->foreign(['tenant_id', 'term_id'])
                ->references(['tenant_id', 'id'])->on('ac_terms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'finalized_by_membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_grade_sheets ADD CONSTRAINT ac_grade_sheets_status_check CHECK (status IN ('DRAFT', 'FINAL'))");
        DB::statement("ALTER TABLE ac_grade_sheets ADD CONSTRAINT ac_grade_sheets_final_check CHECK ((status = 'FINAL') = (finalized_at IS NOT NULL))");

        Schema::create('ac_grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('sheet_id');
            $table->uuid('enrollment_id');
            $table->jsonb('scores');
            $table->unsignedSmallInteger('final_score')->nullable();
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->unique(['sheet_id', 'enrollment_id']);
            $table->index(['enrollment_id']);

            $table->foreign(['tenant_id', 'organization_id', 'sheet_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_grade_sheets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ac_grades ADD CONSTRAINT ac_grades_final_score_check CHECK (final_score IS NULL OR final_score <= 100)');
        DB::statement("ALTER TABLE ac_grades ADD CONSTRAINT ac_grades_scores_check CHECK (jsonb_typeof(scores) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_grades');
        Schema::dropIfExists('ac_grade_sheets');
    }
};
