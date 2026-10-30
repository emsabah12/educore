<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan pengeluaran (PRD-004 §4, KS-3): DIAJUKAN → DISETUJUI | DITOLAK | DIBATALKAN → DIREALISASI.
 * Realisasi = satu transaksi kas KELUAR (`fn_cash_entries.source = PENGELUARAN`, `source_id` = id pengajuan);
 * paling banyak satu realisasi SAH per pengajuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_expense_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->uuid('category_id');
            $table->string('description', 200);
            $table->bigInteger('amount');
            $table->bigInteger('approved_amount')->nullable();
            $table->date('needed_on')->nullable();
            $table->string('status', 12)->default('DIAJUKAN');
            $table->string('attachment_path', 200)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('proof_path', 200)->nullable();
            $table->string('proof_name', 255)->nullable();
            $table->uuid('requested_by_membership_id')->nullable();
            $table->uuid('decided_by_membership_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->uuid('realized_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'organization_id', 'status']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('fn_cash_categories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requested_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'decided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'realized_entry_id'])->references(['tenant_id', 'id'])->on('fn_cash_entries')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_status_check CHECK (status IN ('DIAJUKAN', 'DISETUJUI', 'DITOLAK', 'DIBATALKAN', 'DIREALISASI'))");
        DB::statement('ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_amount_check CHECK (amount BETWEEN 1 AND 999999999999)');
        DB::statement('ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_approved_check CHECK (approved_amount IS NULL OR approved_amount BETWEEN 1 AND amount)');
        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_decision_check CHECK ((status IN ('DISETUJUI', 'DIREALISASI')) = (approved_amount IS NOT NULL))");
        DB::statement("ALTER TABLE fn_expense_requests ADD CONSTRAINT fn_expense_requests_realized_check CHECK ((status = 'DIREALISASI') = (realized_entry_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX fn_cash_entries_expense_unique ON fn_cash_entries (source_id) WHERE source = 'PENGELUARAN' AND status = 'SAH'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fn_cash_entries_expense_unique');
        Schema::dropIfExists('fn_expense_requests');
    }
};
