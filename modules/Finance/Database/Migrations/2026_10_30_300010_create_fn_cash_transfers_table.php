<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi kas (PRD-004 §3, KS-4): pindah uang antar-rekening dalam satu kas, atau antara kas lembaga dan kas Yayasan.
 * Satu mutasi = dua transaksi kas (`source = MUTASI`, `source_id` = id mutasi): KELUAR di rekening asal, MASUK di
 * rekening tujuan. Tidak pernah dihapus (BATAL + alasan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_cash_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('from_account_id');
            $table->uuid('to_account_id');
            $table->date('transfer_date');
            $table->bigInteger('amount');
            $table->string('description', 200);
            $table->string('status', 5)->default('SAH');
            $table->string('void_reason', 200)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_membership_id')->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'from_account_id'])->references(['tenant_id', 'id'])->on('fn_cash_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_account_id'])->references(['tenant_id', 'id'])->on('fn_cash_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'voided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fn_cash_transfers ADD CONSTRAINT fn_cash_transfers_accounts_check CHECK (from_account_id <> to_account_id)');
        DB::statement('ALTER TABLE fn_cash_transfers ADD CONSTRAINT fn_cash_transfers_amount_check CHECK (amount BETWEEN 1 AND 999999999999)');
        DB::statement("ALTER TABLE fn_cash_transfers ADD CONSTRAINT fn_cash_transfers_status_check CHECK (status IN ('SAH', 'BATAL'))");
        DB::statement("ALTER TABLE fn_cash_transfers ADD CONSTRAINT fn_cash_transfers_void_check CHECK ((status = 'BATAL') = (void_reason IS NOT NULL AND voided_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fn_cash_transfers');
    }
};
