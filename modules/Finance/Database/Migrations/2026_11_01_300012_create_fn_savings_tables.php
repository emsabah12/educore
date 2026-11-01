<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabungan siswa (PRD-005 §3, TB-1).
 *
 * - `fn_cash_accounts.kind` mendapat jenis TITIPAN: rekening kas "Titipan tabungan", satu per LEMBAGA, terpisah dari
 *   kas operasional (OD-TB-03). Diisi otomatis dari tabungan (`fn_cash_entries.source = TABUNGAN`).
 * - `fn_savings_accounts`: satu rekening tabungan per siswa per lembaga; `balance` = Σ transaksi SAH (dijaga layanan,
 *   tidak pernah minus).
 * - `fn_savings_entries`: setoran / penarikan / bayar tagihan (TB-3) dengan slip bernomor urut per lembaga per tahun;
 *   tidak pernah dihapus (BATAL + alasan).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fn_cash_accounts ALTER COLUMN kind TYPE varchar(10)');
        DB::statement('ALTER TABLE fn_cash_accounts DROP CONSTRAINT fn_cash_accounts_kind_check');
        DB::statement("ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_kind_check CHECK (kind IN ('TUNAI', 'BANK', 'TITIPAN'))");
        DB::statement("ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_custody_check CHECK (kind <> 'TITIPAN' OR organization_id IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX fn_cash_accounts_custody_org_unique ON fn_cash_accounts (organization_id) WHERE kind = 'TITIPAN'");

        DB::statement('ALTER TABLE fn_cash_entries DROP CONSTRAINT fn_cash_entries_source_check');
        DB::statement("ALTER TABLE fn_cash_entries ADD CONSTRAINT fn_cash_entries_source_check CHECK (source IN ('MANUAL', 'PEMBAYARAN', 'PENGELUARAN', 'MUTASI', 'TABUNGAN'))");
        DB::statement("CREATE UNIQUE INDEX fn_cash_entries_savings_unique ON fn_cash_entries (source_id) WHERE source = 'TABUNGAN'");

        Schema::create('fn_savings_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('student_id');
            $table->bigInteger('balance')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['organization_id', 'student_id']);
            $table->index(['tenant_id', 'organization_id']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'])->references(['tenant_id', 'id'])->on('ac_students')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fn_savings_accounts ADD CONSTRAINT fn_savings_accounts_balance_check CHECK (balance >= 0)');

        Schema::create('fn_savings_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('savings_account_id');
            // Pendaftaran saat transaksi (untuk kelas & nomor induk di slip).
            $table->uuid('enrollment_id');
            $table->date('entry_date');
            $table->string('kind', 15);
            $table->string('method', 10)->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('slip_number', 60);
            $table->unsignedSmallInteger('slip_year');
            $table->unsignedInteger('slip_sequence');
            $table->string('taken_by', 100)->nullable();
            $table->string('note', 200)->nullable();
            $table->uuid('payment_id')->nullable();
            $table->string('status', 5)->default('SAH');
            $table->string('void_reason', 200)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_membership_id')->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['organization_id', 'slip_year', 'slip_sequence']);
            $table->unique(['tenant_id', 'slip_number']);
            $table->index(['savings_account_id', 'entry_date']);
            $table->index(['tenant_id', 'organization_id', 'entry_date']);

            $table->foreign(['tenant_id', 'savings_account_id'])->references(['tenant_id', 'id'])->on('fn_savings_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('fn_payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'voided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_kind_check CHECK (kind IN ('SETOR', 'TARIK', 'BAYAR_TAGIHAN'))");
        DB::statement("ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_method_check CHECK (method IS NULL OR method IN ('TUNAI', 'TRANSFER'))");
        DB::statement("ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_cash_method_check CHECK ((kind = 'BAYAR_TAGIHAN') = (method IS NULL))");
        DB::statement("ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_taken_by_check CHECK ((kind = 'TARIK') = (taken_by IS NOT NULL))");
        DB::statement("ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_payment_check CHECK ((kind = 'BAYAR_TAGIHAN') = (payment_id IS NOT NULL))");
        DB::statement('ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_amount_check CHECK (amount BETWEEN 1 AND 1000000000)');
        DB::statement('ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_balance_check CHECK (balance_after >= 0)');
        DB::statement("ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_status_check CHECK (status IN ('SAH', 'BATAL'))");
        DB::statement("ALTER TABLE fn_savings_entries ADD CONSTRAINT fn_savings_entries_void_check CHECK ((status = 'BATAL') = (void_reason IS NOT NULL AND voided_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fn_savings_entries');
        Schema::dropIfExists('fn_savings_accounts');

        DB::statement('DROP INDEX IF EXISTS fn_cash_entries_savings_unique');
        DB::statement('ALTER TABLE fn_cash_entries DROP CONSTRAINT fn_cash_entries_source_check');
        DB::statement("ALTER TABLE fn_cash_entries ADD CONSTRAINT fn_cash_entries_source_check CHECK (source IN ('MANUAL', 'PEMBAYARAN', 'PENGELUARAN', 'MUTASI'))");

        DB::statement('DROP INDEX IF EXISTS fn_cash_accounts_custody_org_unique');
        DB::statement('ALTER TABLE fn_cash_accounts DROP CONSTRAINT IF EXISTS fn_cash_accounts_custody_check');
        DB::statement('ALTER TABLE fn_cash_accounts DROP CONSTRAINT fn_cash_accounts_kind_check');
        DB::statement("ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_kind_check CHECK (kind IN ('TUNAI', 'BANK'))");
        DB::statement('ALTER TABLE fn_cash_accounts ALTER COLUMN kind TYPE varchar(5)');
    }
};
