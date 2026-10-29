<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tagihan sekali bayar (PRD-003 §4, FN-2b): satu tagihan yang belum batal per pendaftaran × jenis biaya ×
 * tahun ajaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX fn_invoices_once_unique ON fn_invoices (enrollment_id, fee_type_id, academic_year_id) WHERE status <> 'BATAL' AND period IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fn_invoices_once_unique');
    }
};
