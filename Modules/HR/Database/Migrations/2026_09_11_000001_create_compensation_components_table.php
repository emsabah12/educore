<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-006 §7.2 — Compensation Component catalog.
 *
 * Tenant-scoped catalog describing the MEANING of a compensation
 * fact (mis. "BASE_SALARY", "TEACHING_HOUR_RATE"), bukan nilai
 * spesifik per-employee — itu tugas `compensation_assignments`.
 *
 * Sengaja TIDAK ada kolom taxability/accounting-code/BPJS-percentage/
 * PPh-formula/deduction-rule — itu semua domain Finance (belum ada
 * di repo ini), lihat OD-HR-COMP-001 s/d OD-HR-COMP-014.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 60);
            $table->string('name', 150);
            $table->string('category', 30);
            $table->string('value_mode', 30);
            $table->string('unit_code', 20)->nullable();
            $table->string('periodicity', 20);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'code'],
                'uq_compensation_components_tenant_code',
            );

            // Supporting key untuk composite FK dari
            // compensation_assignments.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_compensation_components_id_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_compensation_components_tenant_active',
            );
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_components
                ADD CONSTRAINT chk_compensation_components_category
                CHECK (category IN (
                    'BASE_PAY',
                    'ALLOWANCE',
                    'RATE',
                    'OTHER_EARNING_INPUT'
                ))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_components
                ADD CONSTRAINT chk_compensation_components_value_mode
                CHECK (value_mode IN ('FIXED_AMOUNT', 'RATE_PER_UNIT'))
                SQL,
        );

        // §7.2 — unit_code WAJIB diisi untuk RATE_PER_UNIT (mis. "HOUR"),
        // dan WAJIB kosong untuk FIXED_AMOUNT (tidak relevan).
        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_components
                ADD CONSTRAINT chk_compensation_components_unit_code_by_mode
                CHECK (
                    (value_mode = 'RATE_PER_UNIT' AND unit_code IS NOT NULL)
                    OR
                    (value_mode = 'FIXED_AMOUNT' AND unit_code IS NULL)
                )
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_components
                ADD CONSTRAINT chk_compensation_components_periodicity
                CHECK (periodicity IN (
                    'MONTHLY',
                    'DAILY',
                    'PER_UNIT',
                    'ONE_TIME',
                    'OTHER'
                ))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_components');
    }
};
