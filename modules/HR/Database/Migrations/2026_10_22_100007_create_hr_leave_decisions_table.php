<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan persetujuan cuti per tahap (PRD-001 §13.4, HR-3c).
 *
 * - Tahap 1 = lembaga (pemilik `hr.leave.approve`), tahap 2 = SDM (pemilik `hr.leave.manage`).
 * - DILEWATI = tahap 1 dilewati karena pengaju sendiri penyetuju lembaganya (tanpa pemutus).
 * - Catatan keputusan boleh dibaca pengaju; isinya tidak masuk audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('leave_request_id');
            $table->unsignedSmallInteger('step');
            $table->string('decision', 10);
            $table->uuid('decided_by_membership_id')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestampTz('decided_at');

            $table->index(['leave_request_id', 'step']);

            $table->foreign(['tenant_id', 'leave_request_id'])->references(['tenant_id', 'id'])->on('hr_leave_requests')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'decided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_leave_decisions ADD CONSTRAINT hr_leave_decisions_decision_check CHECK (decision IN ('SETUJU', 'TOLAK', 'DILEWATI') AND step IN (1, 2))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_decisions');
    }
};
