<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat pelatihan & sertifikasi pegawai (PRD-001 §19.1, HR-8a; OD-HR-16).
 *
 * Status: MENUNGGU (diajukan pegawai) → DITERIMA / DITOLAK; catatan Admin langsung DITERIMA; DITERIMA bisa
 * DIBATALKAN. Isian tidak diubah: salah → tolak/batalkan lalu catat ulang. Lampiran sertifikat opsional di disk privat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_trainings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->string('kind', 15);
            $table->string('title', 150);
            $table->string('organizer', 150)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('hours')->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->date('valid_until')->nullable();

            $table->string('storage_path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();

            $table->string('status', 12);
            $table->uuid('submitted_by_membership_id')->nullable();
            $table->uuid('decided_by_membership_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();

            $table->unique('storage_path');
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'end_date']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'submitted_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'decided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_trainings ADD CONSTRAINT hr_trainings_kind_check CHECK (kind IN ('PELATIHAN', 'SEMINAR', 'SERTIFIKASI', 'LAINNYA'))");
        DB::statement("ALTER TABLE hr_trainings ADD CONSTRAINT hr_trainings_status_check CHECK (status IN ('MENUNGGU', 'DITERIMA', 'DITOLAK', 'DIBATALKAN'))");
        DB::statement('ALTER TABLE hr_trainings ADD CONSTRAINT hr_trainings_dates_check CHECK (end_date >= start_date AND (valid_until IS NULL OR valid_until >= end_date))');
        DB::statement('ALTER TABLE hr_trainings ADD CONSTRAINT hr_trainings_hours_check CHECK (hours IS NULL OR hours BETWEEN 1 AND 999)');
        DB::statement('ALTER TABLE hr_trainings ADD CONSTRAINT hr_trainings_title_check CHECK (char_length(btrim(title)) >= 3)');
        DB::statement("ALTER TABLE hr_trainings ADD CONSTRAINT hr_trainings_file_check CHECK ((storage_path IS NULL) = (sha256 IS NULL) AND (sha256 IS NULL OR (byte_size > 0 AND sha256 ~ '^[0-9a-f]{64}$')))");
        DB::statement("ALTER TABLE hr_trainings ADD CONSTRAINT hr_trainings_decision_check CHECK (status NOT IN ('DITOLAK', 'DIBATALKAN') OR (decision_note IS NOT NULL AND btrim(decision_note) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_trainings');
    }
};
