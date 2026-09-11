<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-006 §7.5 — Benefit Program catalog.
 *
 * Tenant-scoped catalog (BPJS_KESEHATAN, BPJS_KETENAGAKERJAAN, TPG,
 * THR, dst. — kode contoh, BUKAN enum global wajib). Sengaja TIDAK
 * ada kolom persentase kontribusi/formula pembayaran statutori —
 * itu domain Finance (sama seperti compensation_components,
 * lihat OD-HR-COMP-007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_programs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 60);
            $table->string('name', 150);
            $table->string('category', 30);
            $table->string('beneficiary_scope', 20);
            $table->string('payroll_relevance', 20);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'code'],
                'uq_benefit_programs_tenant_code',
            );

            // Supporting key untuk composite FK dari
            // employee_benefit_participations.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_benefit_programs_id_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_benefit_programs_tenant_active',
            );
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE benefit_programs
                ADD CONSTRAINT chk_benefit_programs_category
                CHECK (category IN (
                    'STATUTORY',
                    'GOVERNMENT',
                    'INSTITUTIONAL',
                    'OTHER'
                ))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE benefit_programs
                ADD CONSTRAINT chk_benefit_programs_beneficiary_scope
                CHECK (beneficiary_scope IN ('EMPLOYEE', 'DEPENDENT', 'EITHER'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE benefit_programs
                ADD CONSTRAINT chk_benefit_programs_payroll_relevance
                CHECK (payroll_relevance IN (
                    'NONE',
                    'ELIGIBILITY_INPUT',
                    'EXTERNAL_PAYMENT_TRACKING'
                ))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_programs');
    }
};
