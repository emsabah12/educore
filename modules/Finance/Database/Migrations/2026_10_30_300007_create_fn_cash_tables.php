<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kas umum (PRD-004 §3, KS-1).
 *
 * - `fn_cash_categories`: kategori pemasukan/pengeluaran per yayasan; kategori sistem (mis. "Pembayaran siswa",
 *   KS-2) tidak bisa dipilih manual.
 * - `fn_cash_accounts`: rekening kas (Kas tunai otomatis + rekening bank) milik satu LEMBAGA atau kas Yayasan
 *   (organization_id kosong), dengan saldo awal per tanggal.
 * - `fn_cash_entries`: transaksi kas MASUK/KELUAR per rekening; tidak pernah dihapus (BATAL + alasan).
 *   `source` menandai asal transaksi: MANUAL (dicatat bendahara), PEMBAYARAN (kasir, KS-2), PENGELUARAN
 *   (realisasi pengajuan, KS-3), MUTASI (KS-4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_cash_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('direction', 6);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        DB::statement("ALTER TABLE fn_cash_categories ADD CONSTRAINT fn_cash_categories_direction_check CHECK (direction IN ('MASUK', 'KELUAR'))");

        Schema::create('fn_cash_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->string('kind', 5);
            $table->string('name', 100);
            $table->string('bank_name', 60)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->bigInteger('opening_balance')->default(0);
            $table->date('opening_date');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'organization_id']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_kind_check CHECK (kind IN ('TUNAI', 'BANK'))");
        DB::statement('ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_opening_check CHECK (opening_balance BETWEEN 0 AND 1000000000000)');
        DB::statement("ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_bank_check CHECK ((kind = 'BANK') = (bank_name IS NOT NULL))");
        // Satu Kas tunai per kas (lembaga / Yayasan).
        DB::statement("CREATE UNIQUE INDEX fn_cash_accounts_cash_org_unique ON fn_cash_accounts (organization_id) WHERE kind = 'TUNAI' AND organization_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX fn_cash_accounts_cash_tenant_unique ON fn_cash_accounts (tenant_id) WHERE kind = 'TUNAI' AND organization_id IS NULL");

        Schema::create('fn_cash_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->uuid('account_id');
            $table->uuid('category_id');
            $table->date('entry_date');
            $table->string('direction', 6);
            $table->bigInteger('amount');
            $table->string('description', 200);
            $table->string('source', 12)->default('MANUAL');
            $table->uuid('source_id')->nullable();
            $table->string('status', 5)->default('SAH');
            $table->string('void_reason', 200)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_membership_id')->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['account_id', 'entry_date']);
            $table->index(['tenant_id', 'organization_id', 'entry_date']);
            $table->index(['source', 'source_id']);

            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('fn_cash_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('fn_cash_categories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'voided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_cash_entries ADD CONSTRAINT fn_cash_entries_direction_check CHECK (direction IN ('MASUK', 'KELUAR'))");
        DB::statement("ALTER TABLE fn_cash_entries ADD CONSTRAINT fn_cash_entries_source_check CHECK (source IN ('MANUAL', 'PEMBAYARAN', 'PENGELUARAN', 'MUTASI'))");
        DB::statement("ALTER TABLE fn_cash_entries ADD CONSTRAINT fn_cash_entries_status_check CHECK (status IN ('SAH', 'BATAL'))");
        DB::statement('ALTER TABLE fn_cash_entries ADD CONSTRAINT fn_cash_entries_amount_check CHECK (amount BETWEEN 1 AND 1000000000000)');
        DB::statement("ALTER TABLE fn_cash_entries ADD CONSTRAINT fn_cash_entries_void_check CHECK ((status = 'BATAL') = (void_reason IS NOT NULL AND voided_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fn_cash_entries');
        Schema::dropIfExists('fn_cash_accounts');
        Schema::dropIfExists('fn_cash_categories');
    }
};
