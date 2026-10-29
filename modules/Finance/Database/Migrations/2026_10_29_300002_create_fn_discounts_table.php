<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan/beasiswa siswa (PRD-003 §4, FN-1b): per pendaftaran × jenis biaya × tahun ajaran, berupa
 * PERSEN (1–100) atau NOMINAL rupiah (salah satu), dengan alasan. Berlaku untuk tagihan yang dibuat
 * sesudahnya (FN-2); tagihan lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('enrollment_id');
            $table->uuid('fee_type_id');
            $table->uuid('academic_year_id');
            $table->unsignedSmallInteger('percent')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->string('reason', 100);
            $table->uuid('updated_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'fee_type_id', 'academic_year_id']);
            $table->index(['tenant_id', 'organization_id', 'academic_year_id']);

            // Pendaftaran wajib di lembaga yang sama dengan potongan.
            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fee_type_id'])->references(['tenant_id', 'id'])->on('fn_fee_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'])->references(['tenant_id', 'id'])->on('ac_academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'updated_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fn_discounts ADD CONSTRAINT fn_discounts_value_check CHECK ((percent IS NOT NULL AND amount IS NULL AND percent BETWEEN 1 AND 100) OR (percent IS NULL AND amount IS NOT NULL AND amount BETWEEN 1 AND 100000000))');
        DB::statement("ALTER TABLE fn_discounts ADD CONSTRAINT fn_discounts_reason_not_blank_check CHECK (btrim(reason) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('fn_discounts');
    }
};
