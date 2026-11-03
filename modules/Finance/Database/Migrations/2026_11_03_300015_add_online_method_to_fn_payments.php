<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cara bayar ONLINE (PRD-006 §3, MG-2): kuitansi yang dibuat otomatis sesudah Midtrans mengonfirmasi pembayaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fn_payments DROP CONSTRAINT fn_payments_method_check');
        DB::statement("ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_method_check CHECK (method IN ('TUNAI', 'TRANSFER', 'TABUNGAN', 'ONLINE'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fn_payments DROP CONSTRAINT fn_payments_method_check');
        DB::statement("ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_method_check CHECK (method IN ('TUNAI', 'TRANSFER', 'TABUNGAN'))");
    }
};
