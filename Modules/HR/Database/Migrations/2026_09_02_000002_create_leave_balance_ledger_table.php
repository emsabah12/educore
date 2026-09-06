<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.4 — "Append-only entitlement ledger."
 *
 * "ledger rows are not updated/deleted through normal application
 * APIs; corrections use a new compensating entry; final balance =
 * SUM(units_delta) for entitlement." Tabel ini TIDAK PUNYA
 * `updated_at` sama sekali — append-only berarti tidak ada baris yang
 * pernah "diperbarui" secara definisi.
 *
 * `leave_request_id` SENGAJA tanpa FK di migrasi ini — tabel
 * `leave_requests` belum ada (menyusul di Fase D). FK akan ditambahkan
 * lewat migrasi ALTER TABLE terpisah begitu tabel itu dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('entitlement_id');
            $table->string('entry_type', 30);
            $table->decimal('units_delta', 10, 2);
            $table->uuid('leave_request_id')->nullable();
            $table->uuid('reverses_entry_id')->nullable();
            $table->string('idempotency_key', 100);
            $table->uuid('actor_membership_id')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['tenant_id', 'idempotency_key'],
                'uq_leave_balance_ledger_idempotency',
            );

            // Supporting key untuk self-referencing FK reverses_entry_id.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_leave_balance_ledger_id_tenant',
            );

            $table->index(
                ['tenant_id', 'entitlement_id'],
                'idx_leave_balance_ledger_entitlement',
            );

            $table->index(
                'leave_request_id',
                'idx_leave_balance_ledger_leave_request',
            );

            $table->foreign(
                ['entitlement_id', 'tenant_id'],
                'fk_leave_balance_ledger_entitlement_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_entitlements')
                ->restrictOnDelete();

            $table->foreign(
                ['reverses_entry_id', 'tenant_id'],
                'fk_leave_balance_ledger_reverses_entry_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_balance_ledger')
                ->restrictOnDelete();

            $table->foreign(
                'actor_membership_id',
                'fk_leave_balance_ledger_actor_membership',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_balance_ledger
                ADD CONSTRAINT chk_leave_balance_ledger_entry_type
                CHECK (entry_type IN (
                    'GRANT',
                    'CARRYOVER_IN',
                    'CARRYOVER_OUT',
                    'ADJUSTMENT',
                    'CONSUME',
                    'RESTORE',
                    'EXPIRE',
                    'REVERSAL'
                ))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_balance_ledger
                ADD CONSTRAINT chk_leave_balance_ledger_units_delta_nonzero
                CHECK (units_delta <> 0)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_ledger');
    }
};
