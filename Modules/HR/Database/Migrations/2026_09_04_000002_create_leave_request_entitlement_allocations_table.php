<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.8 — "Allocation table between one balance-backed Leave
 * Request and one or more entitlement buckets. This avoids incorrectly
 * assuming every request fits inside exactly one entitlement period."
 *
 * Invariant aplikasi (ditegakkan di service layer, bukan CHECK
 * constraint SQL — butuh SUM lintas baris):
 *   SUM(allocated_units for request) = request.requested_units
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_entitlement_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('leave_request_id');
            $table->uuid('entitlement_id');
            $table->decimal('allocated_units', 10, 2);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'leave_request_id', 'entitlement_id'],
                'uq_leave_request_allocations_request_entitlement',
            );

            $table->index(
                'leave_request_id',
                'idx_leave_request_allocations_request',
            );
            $table->index(
                'entitlement_id',
                'idx_leave_request_allocations_entitlement',
            );

            $table->foreign(
                ['leave_request_id', 'tenant_id'],
                'fk_leave_request_allocations_request_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_requests')
                ->cascadeOnDelete();

            $table->foreign(
                ['entitlement_id', 'tenant_id'],
                'fk_leave_request_allocations_entitlement_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_entitlements')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_request_entitlement_allocations
                ADD CONSTRAINT chk_leave_request_allocations_units_positive
                CHECK (allocated_units > 0)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_entitlement_allocations');
    }
};
