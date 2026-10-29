<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan siswa (PRD-003 §4, FN-2a). Satu baris = satu tagihan untuk satu pendaftaran.
 *
 * - Nominal disalin saat dibuat (tarif, potongan, nominal akhir) — perubahan tarif/potongan sesudahnya tidak
 *   mengubah tagihan ini.
 * - `period` = tanggal 1 bulan tagihan untuk jenis BULANAN; kosong untuk sekali bayar (FN-2b).
 * - Status TERBUKA | LUNAS | BATAL. Tidak pernah dihapus; batal wajib beralasan. `paid_amount` diisi FN-3.
 * - Satu tagihan bulanan yang belum batal per pendaftaran × jenis biaya × bulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('enrollment_id');
            $table->uuid('academic_year_id');
            $table->uuid('fee_type_id');
            $table->date('period')->nullable();
            $table->string('description', 150);
            $table->unsignedSmallInteger('grade')->nullable();
            $table->bigInteger('base_amount');
            $table->bigInteger('discount_amount')->default(0);
            $table->string('discount_label', 30)->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('paid_amount')->default(0);
            $table->date('due_date');
            $table->string('status', 8)->default('TERBUKA');
            $table->string('void_reason', 200)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_membership_id')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'academic_year_id', 'status']);
            $table->index(['enrollment_id', 'status']);

            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'])->references(['tenant_id', 'id'])->on('ac_academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fee_type_id'])->references(['tenant_id', 'id'])->on('fn_fee_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'voided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fn_invoices ADD CONSTRAINT fn_invoices_status_check CHECK (status IN ('TERBUKA', 'LUNAS', 'BATAL'))");
        DB::statement('ALTER TABLE fn_invoices ADD CONSTRAINT fn_invoices_amounts_check CHECK (base_amount BETWEEN 1 AND 100000000 AND discount_amount BETWEEN 0 AND base_amount AND amount = base_amount - discount_amount AND paid_amount BETWEEN 0 AND amount)');
        DB::statement("ALTER TABLE fn_invoices ADD CONSTRAINT fn_invoices_void_check CHECK ((status = 'BATAL') = (void_reason IS NOT NULL AND voided_at IS NOT NULL))");
        DB::statement('ALTER TABLE fn_invoices ADD CONSTRAINT fn_invoices_period_first_day_check CHECK (period IS NULL OR EXTRACT(DAY FROM period) = 1)');
        DB::statement("ALTER TABLE fn_invoices ADD CONSTRAINT fn_invoices_description_not_blank_check CHECK (btrim(description) <> '')");
        DB::statement("CREATE UNIQUE INDEX fn_invoices_monthly_unique ON fn_invoices (enrollment_id, fee_type_id, period) WHERE status <> 'BATAL' AND period IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('fn_invoices');
    }
};
