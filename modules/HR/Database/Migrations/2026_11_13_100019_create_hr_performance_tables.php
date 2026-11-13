<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penilaian kinerja ringkas (PRD-001 §19.2, HR-8b; OD-HR-17, OD-HR-18).
 *
 * - `hr_performance_components`: komponen & bobot per yayasan, terpisah guru/staf. Belum ada baris = isi bawaan.
 * - `hr_performance_periods`: periode penilaian (dikunci di HR-8b-2).
 * - `hr_performance_assessments`: satu penilaian per pegawai per periode. Komponen, bobot, dan nilai disalin ke
 *   `scores` saat menilai, sehingga perubahan komponen sesudahnya tidak mengubah penilaian yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_performance_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('group', 10);
            $table->string('name', 100);
            $table->unsignedSmallInteger('weight');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['tenant_id', 'group', 'position']);
        });

        DB::statement("ALTER TABLE hr_performance_components ADD CONSTRAINT hr_performance_components_group_check CHECK (\"group\" IN ('GURU', 'STAF'))");
        DB::statement('ALTER TABLE hr_performance_components ADD CONSTRAINT hr_performance_components_weight_check CHECK (weight BETWEEN 1 AND 100)');

        Schema::create('hr_performance_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestampTz('locked_at')->nullable();
            $table->uuid('locked_by_membership_id')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'start_date']);

            $table->foreign(['tenant_id', 'locked_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE hr_performance_periods ADD CONSTRAINT hr_performance_periods_dates_check CHECK (end_date >= start_date)');
        DB::statement('CREATE UNIQUE INDEX hr_performance_periods_name_unique ON hr_performance_periods (tenant_id, lower(name))');

        Schema::create('hr_performance_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('period_id');
            $table->uuid('employee_id');
            // Lembaga penempatan utama pegawai saat dinilai: dasar siapa yang boleh melihat/mengubah.
            $table->uuid('organization_id');
            $table->string('group', 10);
            $table->jsonb('scores');
            $table->decimal('final_score', 4, 1);
            $table->string('predicate', 20);
            $table->string('strengths', 500)->nullable();
            $table->string('suggestions', 500)->nullable();
            $table->uuid('assessed_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'employee_id']);
            $table->index(['tenant_id', 'period_id', 'organization_id']);

            $table->foreign(['tenant_id', 'period_id'])->references(['tenant_id', 'id'])->on('hr_performance_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'assessed_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_performance_assessments ADD CONSTRAINT hr_performance_assessments_group_check CHECK (\"group\" IN ('GURU', 'STAF'))");
        DB::statement('ALTER TABLE hr_performance_assessments ADD CONSTRAINT hr_performance_assessments_score_check CHECK (final_score BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_performance_assessments');
        Schema::dropIfExists('hr_performance_periods');
        Schema::dropIfExists('hr_performance_components');
    }
};
