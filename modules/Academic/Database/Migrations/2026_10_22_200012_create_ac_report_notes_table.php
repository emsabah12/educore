<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan wali kelas di rapor (PRD-002 §19 AC-5d): kelas × semester × pendaftaran siswa.
 * Semester yang sudah punya catatan tidak bisa dihapus (restrict; service memberi pesan jelas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_report_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->uuid('class_id');
            $table->uuid('term_id');
            $table->uuid('enrollment_id');
            $table->string('note', 1000);
            $table->uuid('updated_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'term_id', 'enrollment_id']);

            $table->foreign(['tenant_id', 'organization_id', 'academic_year_id', 'class_id'])
                ->references(['tenant_id', 'organization_id', 'academic_year_id', 'id'])->on('ac_classes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'term_id'])
                ->references(['tenant_id', 'id'])->on('ac_terms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'updated_by_membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_report_notes ADD CONSTRAINT ac_report_notes_note_not_blank_check CHECK (btrim(note) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_report_notes');
    }
};
