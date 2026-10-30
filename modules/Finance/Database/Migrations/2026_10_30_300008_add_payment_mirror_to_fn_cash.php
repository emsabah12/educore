<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran siswa masuk buku kas (PRD-004 §3, KS-2).
 *
 * - `fn_cash_accounts.receives_transfers`: rekening bank lembaga yang menerima pembayaran siswa via transfer
 *   (paling banyak satu per lembaga).
 * - Satu baris kas per kuitansi (`source = PEMBAYARAN`, `source_id` = id pembayaran).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fn_cash_accounts', function (Blueprint $table) {
            $table->boolean('receives_transfers')->default(false);
        });

        DB::statement("ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_receiver_check CHECK (NOT receives_transfers OR (kind = 'BANK' AND organization_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX fn_cash_accounts_receiver_unique ON fn_cash_accounts (organization_id) WHERE receives_transfers');
        DB::statement("CREATE UNIQUE INDEX fn_cash_entries_payment_unique ON fn_cash_entries (source_id) WHERE source = 'PEMBAYARAN'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fn_cash_entries_payment_unique');
        DB::statement('DROP INDEX IF EXISTS fn_cash_accounts_receiver_unique');
        DB::statement('ALTER TABLE fn_cash_accounts DROP CONSTRAINT IF EXISTS fn_cash_accounts_receiver_check');

        Schema::table('fn_cash_accounts', function (Blueprint $table) {
            $table->dropColumn('receives_transfers');
        });
    }
};
