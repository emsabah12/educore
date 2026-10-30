<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan pengeluaran berjenjang (PRD-004 §16, KS-5): status baru MENUNGGU_YAYASAN dan jejak persetujuan
 * pertama (lembaga) bila pengajuan naik ke tingkat Yayasan. `decided_*` tetap menyimpan keputusan terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fn_expense_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('DIAJUKAN')->change();
            $table->uuid('first_decided_by_membership_id')->nullable();
            $table->timestampTz('first_decided_at')->nullable();
            $table->bigInteger('first_approved_amount')->nullable();
            $table->string('first_decision_note', 200)->nullable();

            $table->foreign(['tenant_id', 'first_decided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fn_expense_requests DROP CONSTRAINT fn_expense_requests_status_check');
        DB::statement('ALTER TABLE fn_expense_requests DROP CONSTRAINT fn_expense_requests_decision_check');
        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_status_check CHECK (status IN ('DIAJUKAN', 'MENUNGGU_YAYASAN', 'DISETUJUI', 'DITOLAK', 'DIBATALKAN', 'DIREALISASI'))");
        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_decision_check CHECK ((status IN ('MENUNGGU_YAYASAN', 'DISETUJUI', 'DIREALISASI')) = (approved_amount IS NOT NULL))");
        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_first_check CHECK (status <> 'MENUNGGU_YAYASAN' OR first_decided_at IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fn_expense_requests DROP CONSTRAINT fn_expense_requests_first_check');
        DB::statement('ALTER TABLE fn_expense_requests DROP CONSTRAINT fn_expense_requests_status_check');
        DB::statement('ALTER TABLE fn_expense_requests DROP CONSTRAINT fn_expense_requests_decision_check');
        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_status_check CHECK (status IN ('DIAJUKAN', 'DISETUJUI', 'DITOLAK', 'DIBATALKAN', 'DIREALISASI'))");
        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_decision_check CHECK ((status IN ('DISETUJUI', 'DIREALISASI')) = (approved_amount IS NOT NULL))");

        Schema::table('fn_expense_requests', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'first_decided_by_membership_id']);
            $table->dropColumn(['first_decided_by_membership_id', 'first_decided_at', 'first_approved_amount', 'first_decision_note']);
        });
    }
};
