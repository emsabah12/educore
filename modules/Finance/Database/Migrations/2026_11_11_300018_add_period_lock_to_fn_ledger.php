<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\BusinessClock;

/**
 * PB-4 kunci periode (PRD-007 §19): satu tanggal "terkunci sampai" (akhir bulan) per yayasan.
 *
 * Aplikasi menolak transaksi bertanggal di periode terkunci dengan pesan ramah (LedgerLock). Trigger di bawah adalah
 * pengaman kedua: perubahan apa pun yang menyentuh periode terkunci di tabel sumber jurnal (kas, mutasi, tagihan,
 * saldo rekening, saldo tambahan) maupun di jurnal itu sendiri ditolak database dengan pesan `EDUCORE_PERIOD_LOCKED`,
 * sehingga jalur lain (mis. fitur baru nanti) tidak bisa mengubah buku yang sudah ditutup diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fn_ledger_settings', function (Blueprint $table) {
            $table->date('locked_through')->nullable();
        });

        DB::statement("ALTER TABLE fn_ledger_settings ADD CONSTRAINT fn_ledger_settings_lock_check CHECK (
            locked_through IS NULL OR (
                start_date IS NOT NULL
                AND locked_through >= start_date
                AND locked_through = (date_trunc('month', locked_through) + interval '1 month - 1 day')::date
            ))");

        // Zona waktu sekolah untuk tanggal tagihan sekali bayar (dibatasi ke zona Indonesia agar aman ditanam di SQL).
        $timezone = match (BusinessClock::timezone()) {
            'Asia/Makassar' => 'Asia/Makassar',
            'Asia/Jayapura' => 'Asia/Jayapura',
            'Asia/Pontianak' => 'Asia/Pontianak',
            default => 'Asia/Jakarta',
        };

        DB::unprepared(<<<SQL
            -- Tanggal jurnal sebuah baris (NULL = tidak memengaruhi jurnal).
            CREATE OR REPLACE FUNCTION fn_ledger_row_date(kind text, j jsonb, tz text) RETURNS date AS \$\$
            BEGIN
                IF kind = 'invoice' THEN
                    -- Bulanan: tanggal 1 bulan periode; sekali bayar: tanggal dibuat (zona waktu sekolah).
                    RETURN COALESCE((j->>'period')::date, (((j->>'created_at')::timestamp AT TIME ZONE 'UTC') AT TIME ZONE tz)::date);
                ELSIF kind = 'opening' THEN
                    IF COALESCE((j->>'opening_balance')::bigint, 0) = 0 THEN
                        RETURN NULL;
                    END IF;
                    RETURN (j->>'opening_date')::date;
                ELSIF kind = 'line' THEN
                    RETURN (SELECT journal_date FROM fn_journals WHERE id = (j->>'journal_id')::uuid);
                ELSIF kind = 'always' THEN
                    -- Saldo tambahan masuk jurnal saldo awal di tanggal mulai, yang selalu terkunci bila ada kunci.
                    RETURN '-infinity'::date;
                END IF;

                RETURN (j->>kind)::date;
            END;
            \$\$ LANGUAGE plpgsql STABLE;

            CREATE OR REPLACE FUNCTION fn_ledger_lock_guard() RETURNS trigger AS \$\$
            DECLARE
                tenant uuid;
                locked date;
                old_date date;
                new_date date;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    tenant := OLD.tenant_id;
                ELSE
                    tenant := NEW.tenant_id;
                END IF;

                -- FOR KEY SHARE: menunggu bila kunci sedang dipasang, supaya transaksi tidak menyelip ke periode yang baru dikunci.
                SELECT locked_through INTO locked FROM fn_ledger_settings WHERE tenant_id = tenant FOR KEY SHARE;

                IF locked IS NOT NULL THEN
                    IF TG_OP <> 'INSERT' THEN
                        old_date := fn_ledger_row_date(TG_ARGV[0], to_jsonb(OLD), '{$timezone}');
                    END IF;

                    IF TG_OP <> 'DELETE' THEN
                        new_date := fn_ledger_row_date(TG_ARGV[0], to_jsonb(NEW), '{$timezone}');
                    END IF;

                    IF old_date <= locked OR new_date <= locked THEN
                        RAISE EXCEPTION 'EDUCORE_PERIOD_LOCKED %', locked;
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fn_cash_entries_lock_guard
                BEFORE INSERT OR DELETE ON fn_cash_entries
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_lock_guard('entry_date');
            CREATE TRIGGER fn_cash_entries_lock_guard_update
                BEFORE UPDATE ON fn_cash_entries
                FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                EXECUTE FUNCTION fn_ledger_lock_guard('entry_date');

            CREATE TRIGGER fn_cash_transfers_lock_guard
                BEFORE INSERT OR DELETE ON fn_cash_transfers
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_lock_guard('transfer_date');
            CREATE TRIGGER fn_cash_transfers_lock_guard_update
                BEFORE UPDATE ON fn_cash_transfers
                FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                EXECUTE FUNCTION fn_ledger_lock_guard('transfer_date');

            -- Tagihan: hanya perubahan yang memengaruhi jurnal (sama dengan antrean); pembayaran tetap boleh.
            CREATE TRIGGER fn_invoices_lock_guard
                BEFORE INSERT OR DELETE ON fn_invoices
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_lock_guard('invoice');
            CREATE TRIGGER fn_invoices_lock_guard_update
                BEFORE UPDATE ON fn_invoices
                FOR EACH ROW
                WHEN ((OLD.status = 'BATAL') IS DISTINCT FROM (NEW.status = 'BATAL')
                    OR OLD.amount IS DISTINCT FROM NEW.amount
                    OR OLD.base_amount IS DISTINCT FROM NEW.base_amount
                    OR OLD.discount_amount IS DISTINCT FROM NEW.discount_amount
                    OR OLD.period IS DISTINCT FROM NEW.period
                    OR OLD.fee_type_id IS DISTINCT FROM NEW.fee_type_id)
                EXECUTE FUNCTION fn_ledger_lock_guard('invoice');

            CREATE TRIGGER fn_cash_accounts_lock_guard
                BEFORE INSERT OR DELETE ON fn_cash_accounts
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_lock_guard('opening');
            CREATE TRIGGER fn_cash_accounts_lock_guard_update
                BEFORE UPDATE ON fn_cash_accounts
                FOR EACH ROW
                WHEN (OLD.opening_balance IS DISTINCT FROM NEW.opening_balance
                    OR OLD.opening_date IS DISTINCT FROM NEW.opening_date)
                EXECUTE FUNCTION fn_ledger_lock_guard('opening');

            CREATE TRIGGER fn_opening_items_lock_guard
                BEFORE INSERT OR UPDATE OR DELETE ON fn_opening_items
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_lock_guard('always');

            CREATE TRIGGER fn_journals_lock_guard
                BEFORE INSERT OR UPDATE OR DELETE ON fn_journals
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_lock_guard('journal_date');

            CREATE TRIGGER fn_journal_lines_lock_guard
                BEFORE INSERT OR UPDATE OR DELETE ON fn_journal_lines
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_lock_guard('line');

            -- Selama ada kunci, tanggal mulai & bulan awal tahun buku tidak boleh berubah.
            CREATE OR REPLACE FUNCTION fn_ledger_settings_lock_guard() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.locked_through IS NOT NULL AND NEW.locked_through IS NOT NULL
                    AND (OLD.start_date IS DISTINCT FROM NEW.start_date OR OLD.fiscal_start_month IS DISTINCT FROM NEW.fiscal_start_month) THEN
                    RAISE EXCEPTION 'EDUCORE_PERIOD_LOCKED %', OLD.locked_through;
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fn_ledger_settings_lock_guard
                BEFORE UPDATE ON fn_ledger_settings
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_settings_lock_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fn_ledger_settings_lock_guard ON fn_ledger_settings;
            DROP TRIGGER IF EXISTS fn_journal_lines_lock_guard ON fn_journal_lines;
            DROP TRIGGER IF EXISTS fn_journals_lock_guard ON fn_journals;
            DROP TRIGGER IF EXISTS fn_opening_items_lock_guard ON fn_opening_items;
            DROP TRIGGER IF EXISTS fn_cash_accounts_lock_guard_update ON fn_cash_accounts;
            DROP TRIGGER IF EXISTS fn_cash_accounts_lock_guard ON fn_cash_accounts;
            DROP TRIGGER IF EXISTS fn_invoices_lock_guard_update ON fn_invoices;
            DROP TRIGGER IF EXISTS fn_invoices_lock_guard ON fn_invoices;
            DROP TRIGGER IF EXISTS fn_cash_transfers_lock_guard_update ON fn_cash_transfers;
            DROP TRIGGER IF EXISTS fn_cash_transfers_lock_guard ON fn_cash_transfers;
            DROP TRIGGER IF EXISTS fn_cash_entries_lock_guard_update ON fn_cash_entries;
            DROP TRIGGER IF EXISTS fn_cash_entries_lock_guard ON fn_cash_entries;
            DROP FUNCTION IF EXISTS fn_ledger_settings_lock_guard();
            DROP FUNCTION IF EXISTS fn_ledger_lock_guard();
            DROP FUNCTION IF EXISTS fn_ledger_row_date(text, jsonb, text);
            SQL);

        DB::statement('ALTER TABLE fn_ledger_settings DROP CONSTRAINT IF EXISTS fn_ledger_settings_lock_check');

        Schema::table('fn_ledger_settings', function (Blueprint $table) {
            $table->dropColumn('locked_through');
        });
    }
};
