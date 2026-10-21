<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal pelajaran mingguan per kelas (PRD-002 §16 AC-3c).
 *
 * - Satu baris = hari (1 Senin … 7 Ahad) + jam mulai–selesai + mapel kelas itu.
 * - Mapel kelas dan jadwalnya selalu di kelas yang sama (FK gabungan kelas + mapel kelas).
 * - Mapel dilepas dari kelas atau kelas kosong dihapus → jadwalnya ikut terhapus.
 * - Bentrok kelas & bentrok guru diperiksa service (lintas lembaga, tidak bisa lewat constraint biasa).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Target foreign key gabungan dari jadwal: mapel kelas milik kelas yang sama.
        Schema::table('ac_class_subjects', function (Blueprint $table) {
            $table->unique(['tenant_id', 'class_id', 'id']);
        });

        Schema::create('ac_schedule_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->uuid('class_id');
            $table->uuid('class_subject_id');
            $table->unsignedSmallInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['class_id', 'day_of_week']);
            $table->index(['class_subject_id']);
            $table->index(['tenant_id', 'day_of_week']);

            $table->foreign(['tenant_id', 'organization_id', 'academic_year_id', 'class_id'])
                ->references(['tenant_id', 'organization_id', 'academic_year_id', 'id'])->on('ac_classes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'class_id', 'class_subject_id'])
                ->references(['tenant_id', 'class_id', 'id'])->on('ac_class_subjects')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE ac_schedule_entries ADD CONSTRAINT ac_schedule_entries_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE ac_schedule_entries ADD CONSTRAINT ac_schedule_entries_time_check CHECK (end_time > start_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_schedule_entries');

        Schema::table('ac_class_subjects', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'class_id', 'id']);
        });
    }
};
