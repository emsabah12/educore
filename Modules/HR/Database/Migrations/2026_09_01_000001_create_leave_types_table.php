<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.1 — Tenant-scoped Leave/Permit catalog.
 *
 * "Once referenced by entitlement/ledger/request history, `category`,
 * `balance_mode`, and `unit` must not be mutated in ways that
 * reinterpret historical data. Create a new type/version when
 * semantics materially change." — penegakan ini di level service layer
 * (Fase A Step 2 dan seterusnya), migrasi ini murni struktur data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name', 120);
            $table->string('category', 20);
            $table->string('balance_mode', 20);
            $table->string('unit', 10);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'code'],
                'uq_leave_types_tenant_code',
            );

            // Supporting key untuk composite FK dari
            // leave_entitlement_policies, leave_entitlements,
            // leave_approval_policies, dan leave_requests.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_leave_types_id_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_leave_types_tenant_active',
            );

            $table->foreign('tenant_id', 'fk_leave_types_tenant')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_types
                ADD CONSTRAINT chk_leave_types_category
                CHECK (category IN ('LEAVE', 'PERMIT'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_types
                ADD CONSTRAINT chk_leave_types_balance_mode
                CHECK (balance_mode IN ('BALANCE', 'NONE'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_types
                ADD CONSTRAINT chk_leave_types_unit
                CHECK (unit IN ('DAY', 'HOUR'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
