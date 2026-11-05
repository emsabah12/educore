<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembukuan (PRD-007 §3, §4, §13; PB-1).
 *
 * - `fn_ledger_accounts`: COA per yayasan (akun judul & akun posting). `system_key` menandai akun yang dipakai
 *   jurnal otomatis (tidak bisa dinonaktifkan).
 * - `fn_ledger_settings`: tanggal mulai pembukuan, bulan awal tahun buku, status susun ulang.
 * - `fn_journals` + `fn_journal_lines`: jurnal (otomatis dari transaksi aplikasi; manual menyusul PB-2). Debit =
 *   kredit = `amount` dijaga constraint trigger yang dicek saat COMMIT (DEFERRABLE).
 * - `fn_ledger_queue`: antrean transaksi sumber yang berubah. Diisi TRIGGER di tabel sumber (tagihan, transaksi kas,
 *   rekening kas) di transaksi database yang sama, hanya bila pembukuan yayasan sudah aktif; jurnal disusun dari
 *   antrean ini (JournalProjector).
 * - Kolom pemetaan `ledger_account_id` di rekening kas, kategori kas, dan jenis biaya (kosong = akun bawaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('account_group', 10);
            $table->boolean('is_header')->default(false);
            $table->uuid('parent_id')->nullable();
            $table->string('normal_balance', 6);
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_restricted')->default(false);
            $table->string('cash_flow_activity', 10)->nullable();
            $table->string('system_key', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);

            $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('fn_ledger_accounts')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_code_check CHECK (code ~ '^[0-9][0-9.-]{1,19}$')");
        DB::statement("ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_name_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_group_check CHECK (account_group IN ('ASET', 'LIABILITAS', 'ASET_NETO', 'PENDAPATAN', 'BEBAN'))");
        DB::statement("ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_normal_check CHECK (normal_balance IN ('DEBIT', 'KREDIT'))");
        DB::statement("ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_cash_check CHECK (NOT is_cash OR (account_group = 'ASET' AND NOT is_header))");
        DB::statement("ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_restricted_check CHECK (NOT is_restricted OR account_group IN ('PENDAPATAN', 'ASET_NETO'))");
        DB::statement("ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_activity_check CHECK (cash_flow_activity IS NULL OR (cash_flow_activity IN ('OPERASI', 'INVESTASI', 'PENDANAAN') AND NOT is_cash AND NOT is_header))");
        DB::statement('ALTER TABLE fn_ledger_accounts ADD CONSTRAINT fn_ledger_accounts_parent_check CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('CREATE UNIQUE INDEX fn_ledger_accounts_system_key_unique ON fn_ledger_accounts (tenant_id, system_key) WHERE system_key IS NOT NULL');

        Schema::create('fn_ledger_settings', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->unsignedSmallInteger('fiscal_start_month')->default(1);
            // Naik setiap tanggal mulai / pemetaan berubah; susun ulang selesai → rebuilt_version = config_version.
            $table->unsignedInteger('config_version')->default(0);
            $table->unsignedInteger('rebuilt_version')->default(0);
            $table->unsignedInteger('rebuild_version')->nullable();
            $table->string('rebuild_phase', 15)->nullable();
            $table->uuid('rebuild_cursor')->nullable();
            $table->unsignedInteger('rebuild_done')->default(0);
            $table->unsignedInteger('rebuild_total')->default(0);
            $table->timestampTz('rebuild_started_at')->nullable();
            $table->timestampTz('rebuilt_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE fn_ledger_settings ADD CONSTRAINT fn_ledger_settings_month_check CHECK (fiscal_start_month BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE fn_ledger_settings ADD CONSTRAINT fn_ledger_settings_start_check CHECK (start_date IS NULL OR EXTRACT(DAY FROM start_date) = 1)');

        Schema::create('fn_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('number', 30);
            $table->date('journal_date');
            $table->string('description', 250);
            $table->string('source', 15);
            $table->uuid('source_id')->nullable();
            $table->uuid('organization_id')->nullable();
            $table->bigInteger('amount');
            $table->string('status', 5)->default('SAH');
            $table->string('void_reason', 200)->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'journal_date']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_journals ADD CONSTRAINT fn_journals_source_check CHECK (source IN ('TAGIHAN', 'KAS', 'MUTASI', 'SALDO_REKENING', 'SALDO_AWAL', 'MANUAL'))");
        DB::statement("ALTER TABLE fn_journals ADD CONSTRAINT fn_journals_status_check CHECK (status IN ('SAH', 'BATAL'))");
        DB::statement('ALTER TABLE fn_journals ADD CONSTRAINT fn_journals_amount_check CHECK (amount >= 1)');
        DB::statement("ALTER TABLE fn_journals ADD CONSTRAINT fn_journals_void_check CHECK ((status = 'BATAL') = (void_reason IS NOT NULL))");
        // Satu jurnal per transaksi sumber.
        DB::statement('CREATE UNIQUE INDEX fn_journals_source_unique ON fn_journals (tenant_id, source, source_id) WHERE source_id IS NOT NULL');

        Schema::create('fn_journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('journal_id');
            $table->unsignedSmallInteger('line_no');
            $table->uuid('account_id');
            $table->uuid('organization_id')->nullable();
            $table->uuid('cash_account_id')->nullable();
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);

            $table->unique(['journal_id', 'line_no']);
            $table->index(['tenant_id', 'account_id']);

            $table->foreign(['tenant_id', 'journal_id'])->references(['tenant_id', 'id'])->on('fn_journals')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('fn_ledger_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_account_id'])->references(['tenant_id', 'id'])->on('fn_cash_accounts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fn_journal_lines ADD CONSTRAINT fn_journal_lines_amount_check CHECK (debit >= 0 AND credit >= 0 AND (debit > 0) <> (credit > 0))');

        Schema::create('fn_ledger_queue', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('source', 15);
            $table->uuid('source_id');
            $table->timestampTz('queued_at')->useCurrent();

            $table->primary(['source', 'source_id']);
            $table->index(['tenant_id', 'queued_at']);
        });

        foreach (['fn_cash_accounts', 'fn_cash_categories', 'fn_fee_types'] as $mapped) {
            Schema::table($mapped, function (Blueprint $table) use ($mapped) {
                $table->uuid('ledger_account_id')->nullable();
                $table->foreign(['tenant_id', 'ledger_account_id'], "{$mapped}_ledger_account_fk")->references(['tenant_id', 'id'])->on('fn_ledger_accounts')->restrictOnDelete();
            });
        }

        // Nomor jurnal JU-<tahun>-<urut>: urutan naik tanpa kunci bersama (boleh ada lompatan, tidak dipakai ulang).
        // OWNED BY → ikut terhapus bersama tabel jurnal (mis. migrate:fresh).
        DB::statement('CREATE SEQUENCE IF NOT EXISTS fn_journal_number_seq OWNED BY fn_journals.number');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_ledger_enqueue() RETURNS trigger AS $$
            DECLARE
                rec record;
                queued_source text;
                queued_id uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    rec := OLD;
                ELSE
                    rec := NEW;
                END IF;

                IF NOT EXISTS (SELECT 1 FROM fn_ledger_settings s WHERE s.tenant_id = rec.tenant_id AND s.start_date IS NOT NULL) THEN
                    RETURN NULL;
                END IF;

                queued_source := TG_ARGV[0];
                queued_id := rec.id;

                -- Dua baris kas sebuah mutasi = satu jurnal mutasi (sumber: fn_cash_transfers).
                IF TG_TABLE_NAME = 'fn_cash_entries' THEN
                    IF rec.source = 'MUTASI' THEN
                        queued_source := 'MUTASI';
                        queued_id := rec.source_id;
                    END IF;
                END IF;

                IF queued_id IS NULL THEN
                    RETURN NULL;
                END IF;

                INSERT INTO fn_ledger_queue (tenant_id, source, source_id, queued_at)
                VALUES (rec.tenant_id, queued_source, queued_id, now())
                ON CONFLICT (source, source_id) DO NOTHING;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fn_cash_entries_ledger_enqueue
                AFTER INSERT OR UPDATE OR DELETE ON fn_cash_entries
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_enqueue('KAS');

            CREATE TRIGGER fn_invoices_ledger_enqueue_insert
                AFTER INSERT OR DELETE ON fn_invoices
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_enqueue('TAGIHAN');

            -- Pembayaran mengubah paid_amount/status LUNAS: tidak memengaruhi jurnal tagihan, jadi tidak diantrekan.
            CREATE TRIGGER fn_invoices_ledger_enqueue_update
                AFTER UPDATE ON fn_invoices
                FOR EACH ROW
                WHEN ((OLD.status = 'BATAL') IS DISTINCT FROM (NEW.status = 'BATAL')
                    OR OLD.amount IS DISTINCT FROM NEW.amount
                    OR OLD.base_amount IS DISTINCT FROM NEW.base_amount
                    OR OLD.discount_amount IS DISTINCT FROM NEW.discount_amount
                    OR OLD.period IS DISTINCT FROM NEW.period
                    OR OLD.fee_type_id IS DISTINCT FROM NEW.fee_type_id)
                EXECUTE FUNCTION fn_ledger_enqueue('TAGIHAN');

            CREATE TRIGGER fn_cash_accounts_ledger_enqueue_insert
                AFTER INSERT OR DELETE ON fn_cash_accounts
                FOR EACH ROW EXECUTE FUNCTION fn_ledger_enqueue('SALDO_REKENING');

            CREATE TRIGGER fn_cash_accounts_ledger_enqueue_update
                AFTER UPDATE ON fn_cash_accounts
                FOR EACH ROW
                WHEN (OLD.opening_balance IS DISTINCT FROM NEW.opening_balance
                    OR OLD.opening_date IS DISTINCT FROM NEW.opening_date)
                EXECUTE FUNCTION fn_ledger_enqueue('SALDO_REKENING');

            CREATE OR REPLACE FUNCTION fn_journal_check_balance() RETURNS trigger AS $$
            DECLARE
                checked_id uuid;
                total bigint;
                debit_sum bigint;
                credit_sum bigint;
            BEGIN
                IF TG_TABLE_NAME = 'fn_journal_lines' THEN
                    IF TG_OP = 'DELETE' THEN
                        checked_id := OLD.journal_id;
                    ELSE
                        checked_id := NEW.journal_id;
                    END IF;
                ELSE
                    checked_id := NEW.id;
                END IF;

                SELECT amount INTO total FROM fn_journals WHERE id = checked_id;

                -- Jurnal dihapus (barisnya ikut terhapus): tidak ada yang perlu dicek.
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0) INTO debit_sum, credit_sum
                FROM fn_journal_lines WHERE journal_id = checked_id;

                IF debit_sum <> credit_sum OR debit_sum <> total THEN
                    RAISE EXCEPTION 'Jurnal % tidak seimbang: debit %, kredit %, total %.', checked_id, debit_sum, credit_sum, total;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fn_journal_lines_balanced
                AFTER INSERT OR UPDATE OR DELETE ON fn_journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION fn_journal_check_balance();

            CREATE CONSTRAINT TRIGGER fn_journals_balanced
                AFTER INSERT OR UPDATE ON fn_journals
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION fn_journal_check_balance();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fn_cash_entries_ledger_enqueue ON fn_cash_entries;
            DROP TRIGGER IF EXISTS fn_invoices_ledger_enqueue_insert ON fn_invoices;
            DROP TRIGGER IF EXISTS fn_invoices_ledger_enqueue_update ON fn_invoices;
            DROP TRIGGER IF EXISTS fn_cash_accounts_ledger_enqueue_insert ON fn_cash_accounts;
            DROP TRIGGER IF EXISTS fn_cash_accounts_ledger_enqueue_update ON fn_cash_accounts;
            DROP FUNCTION IF EXISTS fn_ledger_enqueue();
            DROP SEQUENCE IF EXISTS fn_journal_number_seq;
        SQL);

        foreach (['fn_cash_accounts', 'fn_cash_categories', 'fn_fee_types'] as $mapped) {
            Schema::table($mapped, function (Blueprint $table) use ($mapped) {
                $table->dropForeign("{$mapped}_ledger_account_fk");
                $table->dropColumn('ledger_account_id');
            });
        }

        Schema::dropIfExists('fn_ledger_queue');
        Schema::dropIfExists('fn_journal_lines');
        Schema::dropIfExists('fn_journals');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_journal_check_balance()');
        Schema::dropIfExists('fn_ledger_settings');
        Schema::dropIfExists('fn_ledger_accounts');
    }
};
