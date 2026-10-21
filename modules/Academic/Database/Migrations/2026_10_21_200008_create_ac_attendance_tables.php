<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi siswa (PRD-002 §17, AC-4a).
 *
 * - Sesi = kelas + tanggal (+ baris jadwal untuk presensi per pertemuan, AC-4c). Satu sesi harian
 *   per kelas per tanggal.
 * - Baris presensi = status H/S/I/A per pendaftaran; siswa dan kelas selalu di lembaga yang sama.
 * - Sesi tidak dihapus lewat aplikasi; kelas yang punya sesi presensi pasti pernah punya anggota,
 *   jadi tidak bisa dihapus (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_attendance_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->uuid('class_id');
            $table->date('date');
            // Diisi di AC-4c (per pertemuan); kosong = presensi harian.
            $table->uuid('schedule_entry_id')->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'date']);
            $table->unique(['tenant_id', 'organization_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id', 'academic_year_id', 'class_id'])
                ->references(['tenant_id', 'organization_id', 'academic_year_id', 'id'])->on('ac_classes')->restrictOnDelete();
            $table->foreign('schedule_entry_id')->references('id')->on('ac_schedule_entries')->nullOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX ac_attendance_sessions_daily_unique ON ac_attendance_sessions (class_id, date) WHERE schedule_entry_id IS NULL');

        Schema::create('ac_attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('session_id');
            $table->uuid('enrollment_id');
            $table->string('status', 1);
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'enrollment_id']);
            $table->index(['enrollment_id']);

            $table->foreign(['tenant_id', 'organization_id', 'session_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_attendance_sessions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_attendance_records ADD CONSTRAINT ac_attendance_records_status_check CHECK (status IN ('H', 'S', 'I', 'A'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_attendance_records');
        Schema::dropIfExists('ac_attendance_sessions');
    }
};
