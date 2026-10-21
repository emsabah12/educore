<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mata pelajaran per lembaga (PRD-002 §16 AC-3a).
 *
 * - Nama unik per lembaga tanpa beda huruf besar/kecil; kode singkat opsional, unik per lembaga.
 * - Rentang tingkat: keduanya kosong = semua tingkat; bila diisi 1–12 dan min ≤ maks.
 * - Target foreign key gabungan (tenant, lembaga, id) untuk mapel kelas di AC-3b.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->unsignedSmallInteger('grade_min')->nullable();
            $table->unsignedSmallInteger('grade_max')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
            $table->unique(['tenant_id', 'organization_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_subjects ADD CONSTRAINT ac_subjects_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE ac_subjects ADD CONSTRAINT ac_subjects_code_not_blank_check CHECK (code IS NULL OR btrim(code) <> '')");
        DB::statement('ALTER TABLE ac_subjects ADD CONSTRAINT ac_subjects_grades_check CHECK ((grade_min IS NULL AND grade_max IS NULL) OR (grade_min IS NOT NULL AND grade_max IS NOT NULL AND grade_min BETWEEN 1 AND 12 AND grade_max BETWEEN 1 AND 12 AND grade_min <= grade_max))');
        DB::statement('CREATE UNIQUE INDEX ac_subjects_name_unique ON ac_subjects (organization_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX ac_subjects_code_unique ON ac_subjects (organization_id, upper(code)) WHERE code IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_subjects');
    }
};
