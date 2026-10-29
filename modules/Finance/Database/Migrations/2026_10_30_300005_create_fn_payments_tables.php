<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran siswa & alokasinya ke tagihan (PRD-003 §4, FN-3).
 *
 * - `fn_payments`: satu pembayaran = satu kuitansi bernomor urut per lembaga per tahun (tahun tanggal
 *   bayar), metode TUNAI/TRANSFER, status SAH/BATAL (batal wajib beralasan; tidak pernah dihapus).
 * - `fn_payment_allocations`: pembagian jumlah pembayaran ke tagihan (satu pembayaran bisa melunasi
 *   beberapa tagihan; satu tagihan bisa dicicil beberapa pembayaran).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('enrollment_id');
            $table->string('receipt_number', 60);
            $table->unsignedSmallInteger('receipt_year');
            $table->unsignedInteger('receipt_sequence');
            $table->date('paid_on');
            $table->string('method', 10);
            $table->bigInteger('amount');
            $table->string('note', 200)->nullable();
            $table->string('status', 5)->default('SAH');
            $table->string('void_reason', 200)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_membership_id')->nullable();
            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['organization_id', 'receipt_year', 'receipt_sequence']);
            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'organization_id', 'paid_on']);
            $table->index(['enrollment_id', 'paid_on']);

            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'voided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_method_check CHECK (method IN ('TUNAI', 'TRANSFER'))");
        DB::statement("ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_status_check CHECK (status IN ('SAH', 'BATAL'))");
        DB::statement('ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_amount_check CHECK (amount BETWEEN 1 AND 1000000000)');
        DB::statement("ALTER TABLE fn_payments ADD CONSTRAINT fn_payments_void_check CHECK ((status = 'BATAL') = (void_reason IS NOT NULL AND voided_at IS NOT NULL))");

        Schema::create('fn_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->bigInteger('amount');
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id']);
            $table->index('invoice_id');

            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('fn_payments')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('fn_invoices')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fn_payment_allocations ADD CONSTRAINT fn_payment_allocations_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fn_payment_allocations');
        Schema::dropIfExists('fn_payments');
    }
};
