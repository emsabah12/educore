<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan cuti (PRD-001 §13.3, HR-3b).
 *
 * - Lembaga pengajuan = lembaga penempatan utama pegawai saat mengajukan (penyetuju tahap 1 &
 *   kalender kerja).
 * - `days` = jumlah hari kerja; `days_by_year` = rincian per tahun kalender, dipakai saldo jatah
 *   (pengajuan lintas tahun dipotong ke saldo masing-masing tahun).
 * - `approval_steps` (1|2) disalin dari aturan `hr.leave_approval` saat diajukan; `current_step`
 *   = tahap yang sedang menunggu.
 * - Alasan = data pribadi; tidak masuk audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->uuid('organization_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days');
            $table->jsonb('days_by_year');
            $table->string('reason', 500);
            $table->string('status', 15);
            $table->unsignedSmallInteger('approval_steps');
            $table->unsignedSmallInteger('current_step')->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['employee_id', 'start_date']);
            $table->index(['organization_id', 'status']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'leave_type_id'])->references(['tenant_id', 'id'])->on('hr_leave_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_requests_status_check CHECK (status IN ('MENUNGGU', 'DISETUJUI', 'DITOLAK', 'DITARIK', 'DIBATALKAN'))");
        DB::statement('ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_requests_dates_check CHECK (end_date >= start_date)');
        DB::statement('ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_requests_days_check CHECK (days >= 1)');
        DB::statement('ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_requests_steps_check CHECK (approval_steps IN (1, 2) AND ((status = \'MENUNGGU\') = (current_step IS NOT NULL)) AND (current_step IS NULL OR current_step BETWEEN 1 AND approval_steps))');
        DB::statement("ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_requests_reason_not_blank_check CHECK (btrim(reason) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_requests');
    }
};
