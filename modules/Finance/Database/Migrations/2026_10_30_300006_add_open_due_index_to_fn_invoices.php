<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Tunggakan & kartu Beranda (PRD-003 FN-4a): cari tagihan Belum lunas yang lewat jatuh tempo per lembaga
 * tanpa memindai semua tagihan (yang lunas/batal tidak masuk indeks).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX fn_invoices_open_due_index ON fn_invoices (tenant_id, organization_id, due_date) WHERE status = 'TERBUKA'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fn_invoices_open_due_index');
    }
};
