<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aspek penilaian (PRD-002 §22 AC-8a):
 * - `ac_subjects.grading_aspects`: kode aspek yang dinilai pada mapel ini (null = semua aspek).
 * - `ac_grades.final_score` menjadi desimal 1 angka di belakang koma (rata-rata nilai aspek,
 *   mis. 86,5). Nilai lama (bilangan bulat) tidak berubah: 85 → 85,0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ac_subjects ADD COLUMN grading_aspects jsonb NULL');
        DB::statement("ALTER TABLE ac_subjects ADD CONSTRAINT ac_subjects_grading_aspects_check CHECK (grading_aspects IS NULL OR jsonb_typeof(grading_aspects) = 'array')");

        DB::statement('ALTER TABLE ac_grades ALTER COLUMN final_score TYPE numeric(4, 1) USING final_score::numeric(4, 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ac_grades ALTER COLUMN final_score TYPE smallint USING round(final_score)::smallint');

        DB::statement('ALTER TABLE ac_subjects DROP CONSTRAINT IF EXISTS ac_subjects_grading_aspects_check');
        DB::statement('ALTER TABLE ac_subjects DROP COLUMN IF EXISTS grading_aspects');
    }
};
