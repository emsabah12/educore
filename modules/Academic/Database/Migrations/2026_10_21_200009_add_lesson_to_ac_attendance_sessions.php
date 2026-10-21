<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi per pertemuan (PRD-002 §17 AC-4c).
 *
 * Sesi pertemuan menyimpan mapel kelas & jam saat diisi, sehingga riwayatnya tidak berubah bila
 * baris jadwal diubah atau dihapus (schedule_entry_id menjadi kosong, mapel & jam tetap).
 * Jenis sesi ditentukan class_subject_id: kosong = harian, terisi = per pertemuan.
 * Mapel kelas yang sudah punya presensi tidak bisa dihapus (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ac_attendance_sessions', function (Blueprint $table) {
            $table->uuid('class_subject_id')->nullable()->after('schedule_entry_id');
            $table->time('start_time')->nullable()->after('class_subject_id');
            $table->time('end_time')->nullable()->after('start_time');

            $table->index(['class_subject_id', 'date']);

            $table->foreign(['tenant_id', 'class_id', 'class_subject_id'])
                ->references(['tenant_id', 'class_id', 'id'])->on('ac_class_subjects')->restrictOnDelete();
        });

        DB::statement('DROP INDEX ac_attendance_sessions_daily_unique');
        DB::statement('CREATE UNIQUE INDEX ac_attendance_sessions_daily_unique ON ac_attendance_sessions (class_id, date) WHERE class_subject_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX ac_attendance_sessions_lesson_unique ON ac_attendance_sessions (schedule_entry_id, date) WHERE schedule_entry_id IS NOT NULL');
        DB::statement('ALTER TABLE ac_attendance_sessions ADD CONSTRAINT ac_attendance_sessions_kind_check CHECK ((class_subject_id IS NULL AND schedule_entry_id IS NULL AND start_time IS NULL AND end_time IS NULL) OR (class_subject_id IS NOT NULL AND start_time IS NOT NULL AND end_time IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ac_attendance_sessions DROP CONSTRAINT ac_attendance_sessions_kind_check');
        DB::statement('DROP INDEX ac_attendance_sessions_lesson_unique');
        DB::statement('DROP INDEX ac_attendance_sessions_daily_unique');
        DB::statement('CREATE UNIQUE INDEX ac_attendance_sessions_daily_unique ON ac_attendance_sessions (class_id, date) WHERE schedule_entry_id IS NULL');

        Schema::table('ac_attendance_sessions', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'class_id', 'class_subject_id']);
            $table->dropIndex(['class_subject_id', 'date']);
            $table->dropColumn(['class_subject_id', 'start_time', 'end_time']);
        });
    }
};
