<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cara bayar TABUNGAN (PRD-005 §4.3, TB-3): tagihan dibayar dari saldo tabungan siswa; transaksi tabungannya
 * (`fn_savings_entries.kind = BAYAR_TAGIHAN`, `payment_id`) dibuat di transaksi yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fn_payments DROP CONSTRAINT fn_payments_method_check');
        DB::statement("ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_method_check CHECK (method IN ('TUNAI', 'TRANSFER', 'TABUNGAN'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fn_payments DROP CONSTRAINT fn_payments_method_check');
        DB::statement("ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_method_check CHECK (method IN ('TUNAI', 'TRANSFER'))");
    }
};
