<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran online Midtrans (PRD-006 §3, §6, §7; MG-1).
 *
 * - `fn_online_payment_methods`: metode yang dibuka per yayasan + biaya layanan (nominal tetap untuk VA, basis poin
 *   untuk QRIS: 70 = 0,70%). Tidak ada baris / nonaktif = metode tidak ditawarkan.
 * - `fn_online_payments`: pesanan bayar online orang tua (order_id unik ke Midtrans), tagihan yang dibayar disimpan di
 *   `invoices` (JSON: id + sisa saat pesanan dibuat). Tidak pernah dihapus.
 * - `fn_online_payment_events`: jejak notifikasi Midtrans (termasuk yang tanda tangannya tidak valid).
 * - `fn_cash_accounts.receives_online`: rekening bank lembaga penerima pembayaran online di buku kas (≤ 1 per lembaga).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_online_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('method', 15);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('fee_value')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'method']);
        });

        DB::statement("ALTER TABLE fn_online_payment_methods ADD CONSTRAINT fn_online_payment_methods_method_check CHECK (method IN ('BCA_VA', 'BNI_VA', 'BRI_VA', 'MANDIRI_BILL', 'PERMATA_VA', 'QRIS'))");
        DB::statement('ALTER TABLE fn_online_payment_methods ADD CONSTRAINT fn_online_payment_methods_fee_check CHECK (fee_value <= 100000)');

        Schema::create('fn_online_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('enrollment_id');
            $table->string('order_id', 50)->unique();
            $table->jsonb('invoices');
            $table->bigInteger('invoice_amount');
            $table->bigInteger('fee_amount');
            $table->bigInteger('gross_amount');
            $table->string('method', 15);
            $table->string('status', 12)->default('MENUNGGU');
            $table->timestampTz('expires_at');
            $table->string('snap_token', 100)->nullable();
            $table->string('redirect_url', 300)->nullable();
            $table->string('midtrans_transaction_id', 100)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->bigInteger('overpaid_amount')->default(0);
            $table->timestampTz('overpaid_settled_at')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'organization_id', 'created_at']);
            $table->index(['enrollment_id', 'status']);

            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('fn_payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_online_payments ADD CONSTRAINT fn_online_payments_status_check CHECK (status IN ('MENUNGGU', 'LUNAS', 'KEDALUWARSA', 'GAGAL', 'DIBATALKAN'))");
        DB::statement("ALTER TABLE fn_online_payments ADD CONSTRAINT fn_online_payments_method_check CHECK (method IN ('BCA_VA', 'BNI_VA', 'BRI_VA', 'MANDIRI_BILL', 'PERMATA_VA', 'QRIS'))");
        DB::statement('ALTER TABLE fn_online_payments ADD CONSTRAINT fn_online_payments_amount_check CHECK (invoice_amount >= 1 AND fee_amount >= 0 AND gross_amount = invoice_amount + fee_amount)');
        DB::statement("ALTER TABLE fn_online_payments ADD CONSTRAINT fn_online_payments_paid_check CHECK ((status = 'LUNAS') = (paid_at IS NOT NULL))");
        DB::statement('ALTER TABLE fn_online_payments ADD CONSTRAINT fn_online_payments_overpaid_check CHECK (overpaid_amount >= 0)');
        // Satu pesanan menunggu per pendaftaran (PRD-006 §3).
        DB::statement("CREATE UNIQUE INDEX fn_online_payments_one_pending ON fn_online_payments (enrollment_id) WHERE status = 'MENUNGGU'");

        Schema::create('fn_online_payment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('online_payment_id')->nullable();
            $table->string('order_id', 50)->nullable();
            $table->string('transaction_status', 30)->nullable();
            $table->boolean('signature_valid');
            $table->jsonb('payload');
            $table->timestampTz('received_at');

            $table->index('order_id');
            $table->index('online_payment_id');

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('online_payment_id')->references('id')->on('fn_online_payments')->restrictOnDelete();
        });

        Schema::table('fn_cash_accounts', function (Blueprint $table) {
            $table->boolean('receives_online')->default(false);
        });

        DB::statement("ALTER TABLE fn_cash_accounts ADD CONSTRAINT fn_cash_accounts_online_check CHECK (NOT receives_online OR (kind = 'BANK' AND organization_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX fn_cash_accounts_online_unique ON fn_cash_accounts (organization_id) WHERE receives_online');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fn_cash_accounts_online_unique');
        DB::statement('ALTER TABLE fn_cash_accounts DROP CONSTRAINT IF EXISTS fn_cash_accounts_online_check');

        Schema::table('fn_cash_accounts', function (Blueprint $table) {
            $table->dropColumn('receives_online');
        });

        Schema::dropIfExists('fn_online_payment_events');
        Schema::dropIfExists('fn_online_payments');
        Schema::dropIfExists('fn_online_payment_methods');
    }
};
