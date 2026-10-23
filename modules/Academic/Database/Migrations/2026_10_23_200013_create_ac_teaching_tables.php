<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi mengajar & jadwal piket (PRD-002 §20, AC-6a).
 *
 * - `ac_duty_rosters`: guru piket per lembaga × tahun ajaran × hari (1 Senin – 7 Ahad).
 * - `ac_teaching_records`: satu catatan per pertemuan (baris jadwal × tanggal) di lembaga kelasnya.
 *   Mapel kelas, jam, dan pengampu disalin saat dicatat sehingga riwayat tetap bila jadwal diubah
 *   atau dihapus (schedule_entry_id menjadi kosong).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_duty_rosters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->unsignedSmallInteger('day_of_week');
            $table->uuid('employee_id');
            $table->timestampTz('created_at');

            $table->unique(['organization_id', 'academic_year_id', 'day_of_week', 'employee_id']);
            $table->index(['tenant_id', 'employee_id', 'day_of_week']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'])->references(['tenant_id', 'id'])->on('ac_academic_years')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ac_duty_rosters ADD CONSTRAINT ac_duty_rosters_day_check CHECK (day_of_week BETWEEN 1 AND 7)');

        Schema::create('ac_teaching_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->uuid('class_id');
            $table->uuid('class_subject_id');
            $table->uuid('schedule_entry_id')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->uuid('teacher_employee_id')->nullable();
            $table->string('status', 12);
            $table->uuid('substitute_employee_id')->nullable();
            $table->string('absence_reason', 12)->nullable();
            $table->string('note', 300)->nullable();
            $table->string('source', 10);
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'date']);
            $table->index(['teacher_employee_id', 'date']);
            $table->index(['substitute_employee_id', 'date']);

            $table->foreign(['tenant_id', 'organization_id', 'academic_year_id', 'class_id'])
                ->references(['tenant_id', 'organization_id', 'academic_year_id', 'id'])->on('ac_classes')->cascadeOnDelete();
            // Mapel kelas yang sudah punya presensi mengajar tidak bisa dilepas (dijaga juga di service).
            // NO ACTION (bukan RESTRICT): menghapus kelas ikut menghapus mapel & catatan dalam satu perintah.
            $table->foreign(['tenant_id', 'class_id', 'class_subject_id'])
                ->references(['tenant_id', 'class_id', 'id'])->on('ac_class_subjects')->noActionOnDelete();
            $table->foreign('schedule_entry_id')->references('id')->on('ac_schedule_entries')->nullOnDelete();
            $table->foreign(['tenant_id', 'teacher_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'substitute_employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX ac_teaching_records_lesson_unique ON ac_teaching_records (schedule_entry_id, date) WHERE schedule_entry_id IS NOT NULL');
        DB::statement("ALTER TABLE ac_teaching_records ADD CONSTRAINT ac_teaching_records_status_check CHECK (status IN ('MENGAJAR', 'DIGANTIKAN', 'KOSONG') AND source IN ('GURU', 'PETUGAS', 'OTOMATIS'))");
        DB::statement("ALTER TABLE ac_teaching_records ADD CONSTRAINT ac_teaching_records_reason_check CHECK (absence_reason IS NULL OR absence_reason IN ('CUTI', 'SAKIT', 'IZIN', 'DINAS_LUAR', 'ALPA', 'LAINNYA'))");
        // Digantikan ⇔ ada pengganti; Mengajar tanpa alasan; Digantikan/Kosong wajib alasan.
        DB::statement("ALTER TABLE ac_teaching_records ADD CONSTRAINT ac_teaching_records_consistency_check CHECK (((status = 'DIGANTIKAN') = (substitute_employee_id IS NOT NULL)) AND ((status = 'MENGAJAR') = (absence_reason IS NULL)) AND end_time > start_time)");
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_teaching_records');
        Schema::dropIfExists('ac_duty_rosters');
    }
};
