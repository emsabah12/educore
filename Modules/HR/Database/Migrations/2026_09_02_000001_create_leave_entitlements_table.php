<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.3 — "One entitlement bucket for one Employment + Leave Type
 * + period."
 *
 * "There is deliberately no `current_balance` source-of-truth column."
 * Saldo SELALU dihitung dari SUM(units_delta) di leave_balance_ledger
 * (Step berikutnya) — tabel ini murni bucket/wadah, bukan penyimpan
 * angka saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employment_id');
            $table->uuid('leave_type_id');
            $table->uuid('entitlement_policy_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            // "One entitlement bucket for one Employment + Leave Type +
            // period" — jantung tabel ini.
            $table->unique(
                ['tenant_id', 'employment_id', 'leave_type_id', 'period_start', 'period_end'],
                'uq_leave_entitlements_period',
            );

            // Supporting key untuk composite FK dari
            // leave_balance_ledger dan leave_request_entitlement_allocations.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_leave_entitlements_id_tenant',
            );

            $table->index(
                ['tenant_id', 'employment_id', 'status'],
                'idx_leave_entitlements_employment_status',
            );

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_leave_entitlements_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                ['leave_type_id', 'tenant_id'],
                'fk_leave_entitlements_leave_type_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_types')
                ->restrictOnDelete();

            $table->foreign(
                ['entitlement_policy_id', 'tenant_id'],
                'fk_leave_entitlements_policy_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_entitlement_policies')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlements
                ADD CONSTRAINT chk_leave_entitlements_period_range
                CHECK (period_end >= period_start)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlements
                ADD CONSTRAINT chk_leave_entitlements_status
                CHECK (status IN ('ACTIVE', 'CLOSED', 'CANCELLED'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlements');
    }
};
