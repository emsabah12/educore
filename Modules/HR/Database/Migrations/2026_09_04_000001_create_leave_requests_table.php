<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.7 — Leave Request lifecycle.
 *
 * "Repository/application default timezone is UTC and Tenant has
 * flexible JSON settings but no dedicated typed timezone field.
 * Therefore Phase 2C stores canonical UTC timestamps plus an explicit
 * `request_timezone` snapshot." — `starts_at`/`ends_at` SELALU UTC;
 * `request_timezone` murni jejak zona waktu asal input pengguna.
 *
 * INV-HR-LEAVE-013 — "Approved overlap forbidden": ditegakkan lewat
 * GiST exclusion constraint (§8.5), bukan cuma validasi aplikasi.
 * Membutuhkan ekstensi `btree_gist` supaya kesetaraan UUID bisa dipakai
 * bersama operator range overlap `&&` dalam satu index GiST.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employment_id');
            $table->uuid('leave_type_id');
            $table->uuid('approval_context_placement_id')->nullable();
            $table->uuid('approval_policy_id')->nullable();
            $table->uuid('submitted_by_membership_id')->nullable();
            $table->string('status', 25)->default('DRAFT');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('request_timezone', 64);
            $table->decimal('requested_units', 10, 2);
            $table->string('unit', 10);
            $table->text('reason')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('final_decided_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            // Supporting key untuk composite FK dari
            // leave_request_entitlement_allocations dan
            // leave_request_approval_steps.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_leave_requests_id_tenant',
            );

            $table->index(
                ['tenant_id', 'employment_id', 'status'],
                'idx_leave_requests_employment_status',
            );
            $table->index(
                ['tenant_id', 'starts_at', 'ends_at'],
                'idx_leave_requests_period',
            );
            $table->index(
                ['tenant_id', 'approval_context_placement_id', 'status'],
                'idx_leave_requests_approval_context_status',
            );
            $table->index(
                'approval_policy_id',
                'idx_leave_requests_policy',
            );

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_leave_requests_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                ['leave_type_id', 'tenant_id'],
                'fk_leave_requests_leave_type_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_types')
                ->restrictOnDelete();

            // 3-kolom — placement harus benar-benar milik Employment
            // YANG SAMA dengan request ini, bukan cuma tenant yang sama.
            $table->foreign(
                ['approval_context_placement_id', 'employment_id', 'tenant_id'],
                'fk_leave_requests_placement_employment_tenant',
            )
                ->references(['id', 'employment_id', 'tenant_id'])
                ->on('employment_placements')
                ->restrictOnDelete();

            $table->foreign(
                ['approval_policy_id', 'tenant_id'],
                'fk_leave_requests_approval_policy_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_approval_policies')
                ->restrictOnDelete();

            $table->foreign(
                'submitted_by_membership_id',
                'fk_leave_requests_submitted_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_requests
                ADD CONSTRAINT chk_leave_requests_period_range
                CHECK (ends_at > starts_at)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_requests
                ADD CONSTRAINT chk_leave_requests_units_positive
                CHECK (requested_units > 0)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_requests
                ADD CONSTRAINT chk_leave_requests_unit
                CHECK (unit IN ('DAY', 'HOUR'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_requests
                ADD CONSTRAINT chk_leave_requests_status
                CHECK (status IN (
                    'DRAFT',
                    'SUBMITTED',
                    'IN_REVIEW',
                    'APPROVED',
                    'REJECTED',
                    'WITHDRAWN',
                    'CANCELLED'
                ))
                SQL,
        );

        // INV-HR-LEAVE-013 — satu-satunya penjaga TERAKHIR yang tidak
        // bisa "diakali" oleh race condition aplikasi manapun.
        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_requests
                ADD CONSTRAINT excl_leave_requests_approved_overlap
                EXCLUDE USING gist (
                    tenant_id WITH =,
                    employment_id WITH =,
                    tstzrange(starts_at, ends_at, '[)') WITH &&
                )
                WHERE (status = 'APPROVED')
                SQL,
        );

        // Fase B Step 2 sengaja menunda FK ini — tabel leave_requests
        // sekarang sudah ada.
        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_balance_ledger
                ADD CONSTRAINT fk_leave_balance_ledger_leave_request_tenant
                FOREIGN KEY (leave_request_id, tenant_id)
                REFERENCES leave_requests (id, tenant_id)
                ON DELETE RESTRICT
                SQL,
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE leave_balance_ledger DROP CONSTRAINT IF EXISTS fk_leave_balance_ledger_leave_request_tenant',
        );

        Schema::dropIfExists('leave_requests');
    }
};
