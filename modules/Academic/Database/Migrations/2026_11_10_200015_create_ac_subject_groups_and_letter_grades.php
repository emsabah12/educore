<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kelompok mapel & nilai huruf (PRD-002 §22.4 AC-8b):
 * - `ac_subject_groups`: kelompok mapel per lembaga (nama unik di lembaga, urutan, ikut jumlah &
 *   rata-rata rapor atau tidak).
 * - `ac_subjects`: kelompok (opsional), urutan (opsional), jenis nilai ANGKA/HURUF.
 * - `ac_grade_sheets.grade_type`: salinan jenis nilai saat lembar pertama kali disimpan.
 * - `ac_grades.letters`: nilai huruf per kode aspek (mapel bernilai huruf).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_subject_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->string('name', 60);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('counts_in_totals')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
            $table->unique(['tenant_id', 'organization_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_subject_groups ADD CONSTRAINT ac_subject_groups_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX ac_subject_groups_name_unique ON ac_subject_groups (organization_id, lower(name))');

        Schema::table('ac_subjects', function (Blueprint $table) {
            $table->uuid('group_id')->nullable();
            $table->smallInteger('sort_order')->nullable();
            $table->string('grade_type', 10)->default('NUMBER');

            // Kelompok harus milik lembaga yang sama dengan mapelnya.
            $table->foreign(['tenant_id', 'organization_id', 'group_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_subject_groups')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_subjects ADD CONSTRAINT ac_subjects_grade_type_check CHECK (grade_type IN ('NUMBER', 'LETTER'))");

        Schema::table('ac_grade_sheets', function (Blueprint $table) {
            $table->string('grade_type', 10)->default('NUMBER');
        });

        DB::statement("ALTER TABLE ac_grade_sheets ADD CONSTRAINT ac_grade_sheets_grade_type_check CHECK (grade_type IN ('NUMBER', 'LETTER'))");
        DB::statement('ALTER TABLE ac_grades ADD COLUMN letters jsonb NULL');
        DB::statement("ALTER TABLE ac_grades ADD CONSTRAINT ac_grades_letters_check CHECK (letters IS NULL OR jsonb_typeof(letters) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ac_grades DROP CONSTRAINT IF EXISTS ac_grades_letters_check');
        DB::statement('ALTER TABLE ac_grades DROP COLUMN IF EXISTS letters');

        Schema::table('ac_grade_sheets', function (Blueprint $table) {
            $table->dropColumn('grade_type');
        });

        Schema::table('ac_subjects', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'organization_id', 'group_id']);
            $table->dropColumn(['group_id', 'sort_order', 'grade_type']);
        });

        Schema::dropIfExists('ac_subject_groups');
    }
};
