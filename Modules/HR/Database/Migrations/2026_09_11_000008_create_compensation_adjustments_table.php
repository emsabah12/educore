<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-006 §7.8 — Compensation Adjustment: input HR earning/koreksi
 * SEKALI-WAKTU yang sudah disetujui (BUKAN tabel deduksi generik —
 * PRD menyebutnya eksplisit).
 *
 * §7.8 "HR does not create negative amount rows. Financial
 * deductions/corrections that reduce payable remain Finance
 * concern." — makanya `amount` SELALU positif, tidak ada konsep
 * "adjustment negatif" di tabel ini sama sekali.
 *
 * Maker-checker WAJIB: `approved_by_membership_id` harus BEDA dari
 * `requested_by_membership_id` untuk baris yang APPROVED — satu
 * orang tidak boleh meminta sekaligus menyetujui penyesuaian
 * kompensasinya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employment_id');
            $table->uuid('compensation_component_id')->nullable();
            $table->string('adjustment_type', 30);
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('target_period_start');
            $table->date('target_period_end');
            $table->string('status', 20)->default('DRAFT');
            $table->text('reason');
            $table->uuid('requested_by_membership_id');
            $table->uuid('approved_by_membership_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->string('idempotency_key', 120);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'idempotency_key'],
                'uq_compensation_adjustments_tenant_idempotency',
            );

            $table->index(
                ['tenant_id', 'employment_id', 'status'],
                'idx_compensation_adjustments_employment_status',
            );

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_compensation_adjustments_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                ['compensation_component_id', 'tenant_id'],
                'fk_compensation_adjustments_component_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('compensation_components')
                ->restrictOnDelete();

            $table->foreign(
                'requested_by_membership_id',
                'fk_compensation_adjustments_requested_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                'approved_by_membership_id',
                'fk_compensation_adjustments_approved_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_adjustments
                ADD CONSTRAINT chk_compensation_adjustments_type
                CHECK (adjustment_type IN ('ONE_TIME_EARNING', 'COMPENSATION_CORRECTION'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_adjustments
                ADD CONSTRAINT chk_compensation_adjustments_status
                CHECK (status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_adjustments
                ADD CONSTRAINT chk_compensation_adjustments_amount_positive
                CHECK (amount > 0)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_adjustments
                ADD CONSTRAINT chk_compensation_adjustments_currency_code
                CHECK (currency_code ~ '^[A-Z]{3}$')
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_adjustments
                ADD CONSTRAINT chk_compensation_adjustments_period_order
                CHECK (target_period_end >= target_period_start)
                SQL,
        );

        // §7.8 Maker-checker — berlaku KAPAN PUN approved_by terisi
        // (bukan cuma saat status=APPROVED), supaya tidak ada celah
        // baris "sudah ada approver tapi status belum APPROVED" yang
        // lolos dari aturan.
        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_adjustments
                ADD CONSTRAINT chk_compensation_adjustments_maker_checker
                CHECK (
                    approved_by_membership_id IS NULL
                    OR approved_by_membership_id != requested_by_membership_id
                )
                SQL,
        );

        // Konsistensi field approval — APPROVED wajib punya
        // approved_by/approved_at; status lain (termasuk CANCELLED)
        // wajib TIDAK punya. Asumsi: CANCELLED hanya bisa terjadi
        // SEBELUM approval (workflow maker-checker khas) — PRD tidak
        // menyebutkan skenario "APPROVED lalu dibatalkan" untuk tabel
        // ini, jadi kami tidak mengarang state tambahan yang tidak
        // berdasar.
        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_adjustments
                ADD CONSTRAINT chk_compensation_adjustments_approval_fields
                CHECK (
                    (
                        status = 'APPROVED'
                        AND approved_by_membership_id IS NOT NULL
                        AND approved_at IS NOT NULL
                    )
                    OR
                    (
                        status != 'APPROVED'
                        AND approved_by_membership_id IS NULL
                        AND approved_at IS NULL
                    )
                )
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_adjustments');
    }
};
