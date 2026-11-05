<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo awal pembukuan (PRD-007 §5, PB-2a).
 *
 * - `fn_opening_items`: baris saldo awal tambahan dari akuntan (aset tetap, utang lain, dll.) per lembaga / Yayasan
 *   (`organization_id` kosong). Digabung dengan saldo kas/piutang/titipan otomatis menjadi satu jurnal SALDO_AWAL per
 *   lembaga; selisihnya ke Aset neto tanpa pembatasan.
 * - Trigger `fn_ledger_enqueue_opening`: transaksi bertanggal SEBELUM tanggal mulai pembukuan (transaksi kas, kuitansi,
 *   tagihan, saldo awal rekening) mengubah saldo awal → jurnal saldo awal lembaganya diantrekan
 *   (`source = SALDO_AWAL`, `source_id` = id lembaga, atau id yayasan untuk kas Yayasan).
 * - Pembukuan yang sudah aktif ditandai "perlu disusun ulang" supaya jurnal saldo awal data lama terbentuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_opening_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->uuid('account_id');
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->string('note', 100)->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'organization_id']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('fn_ledger_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fn_opening_items ADD CONSTRAINT fn_opening_items_amount_check CHECK (debit >= 0 AND credit >= 0 AND (debit > 0) <> (credit > 0))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_ledger_enqueue_opening() RETURNS trigger AS $$
            DECLARE
                rec record;
                ledger_start date;
                affected boolean := false;
                owner uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    rec := OLD;
                ELSE
                    rec := NEW;
                END IF;

                SELECT s.start_date INTO ledger_start FROM fn_ledger_settings s WHERE s.tenant_id = rec.tenant_id;

                IF ledger_start IS NULL THEN
                    RETURN NULL;
                END IF;

                -- Tanggal transaksi (baru & lama) dibandingkan dengan tanggal mulai pembukuan.
                IF TG_TABLE_NAME = 'fn_cash_entries' THEN
                    affected := rec.entry_date < ledger_start OR (TG_OP = 'UPDATE' AND OLD.entry_date < ledger_start);
                ELSIF TG_TABLE_NAME = 'fn_payments' THEN
                    affected := rec.paid_on < ledger_start OR (TG_OP = 'UPDATE' AND OLD.paid_on < ledger_start);
                ELSIF TG_TABLE_NAME = 'fn_cash_accounts' THEN
                    affected := rec.opening_date < ledger_start OR (TG_OP = 'UPDATE' AND OLD.opening_date < ledger_start);
                ELSE
                    -- Tagihan sekali bayar (tanpa periode) selalu dianggap berpengaruh.
                    affected := rec.period IS NULL OR rec.period < ledger_start;
                END IF;

                IF NOT affected THEN
                    RETURN NULL;
                END IF;

                owner := COALESCE(rec.organization_id, rec.tenant_id);

                INSERT INTO fn_ledger_queue (tenant_id, source, source_id, queued_at)
                VALUES (rec.tenant_id, 'SALDO_AWAL', owner, now())
                ON CONFLICT (source, source_id) DO NOTHING;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fn_cash_entries_ledger_opening
                AFTER INSERT OR UPDATE OR DELETE ON fn_cash_entries
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_enqueue_opening();

            CREATE TRIGGER fn_payments_ledger_opening_insert
                AFTER INSERT OR DELETE ON fn_payments
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_enqueue_opening();

            CREATE TRIGGER fn_payments_ledger_opening_update
                AFTER UPDATE ON fn_payments
                FOR EACH ROW
                WHEN (OLD.status IS DISTINCT FROM NEW.status OR OLD.paid_on IS DISTINCT FROM NEW.paid_on OR OLD.amount IS DISTINCT FROM NEW.amount)
                EXECUTE FUNCTION fn_ledger_enqueue_opening();

            CREATE TRIGGER fn_invoices_ledger_opening_insert
                AFTER INSERT OR DELETE ON fn_invoices
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_enqueue_opening();

            CREATE TRIGGER fn_invoices_ledger_opening_update
                AFTER UPDATE ON fn_invoices
                FOR EACH ROW
                WHEN ((OLD.status = 'BATAL') IS DISTINCT FROM (NEW.status = 'BATAL')
                    OR OLD.amount IS DISTINCT FROM NEW.amount
                    OR OLD.period IS DISTINCT FROM NEW.period)
                EXECUTE FUNCTION fn_ledger_enqueue_opening();

            CREATE TRIGGER fn_cash_accounts_ledger_opening_insert
                AFTER INSERT OR DELETE ON fn_cash_accounts
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_enqueue_opening();

            CREATE TRIGGER fn_cash_accounts_ledger_opening_update
                AFTER UPDATE ON fn_cash_accounts
                FOR EACH ROW
                WHEN (OLD.opening_balance IS DISTINCT FROM NEW.opening_balance OR OLD.opening_date IS DISTINCT FROM NEW.opening_date)
                EXECUTE FUNCTION fn_ledger_enqueue_opening();
        SQL);

        // Pembukuan yang sudah aktif (PB-1) perlu disusun ulang agar jurnal saldo awal terbentuk.
        DB::table('fn_ledger_settings')->whereNotNull('start_date')->update(['config_version' => DB::raw('config_version + 1')]);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fn_cash_entries_ledger_opening ON fn_cash_entries;
            DROP TRIGGER IF EXISTS fn_payments_ledger_opening_insert ON fn_payments;
            DROP TRIGGER IF EXISTS fn_payments_ledger_opening_update ON fn_payments;
            DROP TRIGGER IF EXISTS fn_invoices_ledger_opening_insert ON fn_invoices;
            DROP TRIGGER IF EXISTS fn_invoices_ledger_opening_update ON fn_invoices;
            DROP TRIGGER IF EXISTS fn_cash_accounts_ledger_opening_insert ON fn_cash_accounts;
            DROP TRIGGER IF EXISTS fn_cash_accounts_ledger_opening_update ON fn_cash_accounts;
            DROP FUNCTION IF EXISTS fn_ledger_enqueue_opening();
        SQL);

        Schema::dropIfExists('fn_opening_items');
    }
};
