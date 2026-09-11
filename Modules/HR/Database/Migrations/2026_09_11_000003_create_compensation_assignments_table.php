<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-006 §7.3 — Compensation Assignment (effective-dated fact untuk
 * satu Employment).
 *
 * `employment_position_assignment_id` OPSIONAL: kalau diisi, fakta
 * kompensasi ini terikat ke satu Position Assignment tertentu (mis.
 * tunjangan jabatan struktural); kalau null, ini fakta kompensasi
 * tingkat-Employment yang tidak terikat posisi manapun (mis. gaji
 * pokok). Pola sama persis dengan `employment_placement_id` opsional
 * di `employment_position_assignments`.
 *
 * §7.4 — Overlap tanggal APPROVED ditegakkan lewat GiST exclusion
 * constraint (pola identik `excl_leave_requests_approved_overlap`),
 * DIPECAH jadi 2 constraint terpisah (scoped vs unscoped) — PostgreSQL
 * tidak pernah menganggap NULL "sama" dengan NULL lain, jadi satu
 * constraint gabungan akan lolos dari duplikasi baris ber-NULL.
 *
 * §7.3 "Approved-history rule": value fields immutable via normal
 * update API setelah APPROVED — itu ditegakkan di service layer
 * (langkah berikutnya), BUKAN oleh migration ini. Migration ini
 * murni struktur data + constraint yang bisa dijamin DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('compensation_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employment_id');
            $table->uuid('compensation_component_id');
            $table->uuid('employment_position_assignment_id')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->decimal('amount', 19, 4)->nullable();
            $table->decimal('rate', 19, 4)->nullable();
            $table->char('currency_code', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->uuid('supersedes_assignment_id')->nullable();
            $table->uuid('approved_by_membership_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            // Supporting key untuk self-referencing FK
            // (supersedes_assignment_id) di bawah.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_compensation_assignments_id_tenant',
            );

            $table->index(
                ['tenant_id', 'employment_id', 'status'],
                'idx_compensation_assignments_employment_status',
            );
            $table->index(
                ['tenant_id', 'compensation_component_id', 'status'],
                'idx_compensation_assignments_component_status',
            );
            $table->index(
                ['employment_position_assignment_id', 'status'],
                'idx_compensation_assignments_position_assignment_status',
            );

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_compensation_assignments_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                ['compensation_component_id', 'tenant_id'],
                'fk_compensation_assignments_component_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('compensation_components')
                ->restrictOnDelete();

            // 2-kolom sesuai HR-006 §7.3 — kecocokan
            // employment_position_assignment.employment_id dengan
            // compensation_assignment.employment_id adalah
            // APPLICATION INVARIANT (ditegakkan service layer),
            // bukan DB constraint, karena employment_position_assignments
            // belum punya supporting unique key 3-kolom untuk itu.
            $table->foreign(
                ['employment_position_assignment_id', 'tenant_id'],
                'fk_compensation_assignments_position_assignment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employment_position_assignments')
                ->restrictOnDelete();

            $table->foreign(
                ['supersedes_assignment_id', 'tenant_id'],
                'fk_compensation_assignments_supersedes_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('compensation_assignments')
                ->restrictOnDelete();

            $table->foreign(
                'approved_by_membership_id',
                'fk_compensation_assignments_approved_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_assignments
                ADD CONSTRAINT chk_compensation_assignments_status
                CHECK (status IN (
                    'DRAFT',
                    'APPROVED',
                    'ENDED',
                    'CANCELLED',
                    'SUPERSEDED'
                ))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_assignments
                ADD CONSTRAINT chk_compensation_assignments_currency_code
                CHECK (currency_code ~ '^[A-Z]{3}$')
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_assignments
                ADD CONSTRAINT chk_compensation_assignments_effective_to_after_from
                CHECK (effective_to IS NULL OR effective_to >= effective_from)
                SQL,
        );

        // §7.3 value rules — TEPAT SATU dari amount/rate terisi dan
        // positif. Pemetaan tepat ke value_mode ('FIXED_AMOUNT' →
        // amount, 'RATE_PER_UNIT' → rate) milik compensation_component
        // yang direferensikan TIDAK BISA diverifikasi lewat CHECK
        // constraint row-local biasa (CHECK tidak bisa membaca tabel
        // lain) — itu tanggung jawab service layer. Ini murni penjaga
        // struktural "tidak boleh dua-duanya null, tidak boleh dua-duanya
        // terisi, tidak boleh negatif/nol".
        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_assignments
                ADD CONSTRAINT chk_compensation_assignments_amount_xor_rate
                CHECK (
                    (amount IS NOT NULL AND rate IS NULL AND amount > 0)
                    OR
                    (rate IS NOT NULL AND amount IS NULL AND rate > 0)
                )
                SQL,
        );

        // §7.3 "Required for approved state" — approved_by/approved_at
        // WAJIB terisi untuk status yang PERNAH melewati approval
        // (APPROVED, ENDED, SUPERSEDED semuanya "pasca-approval"),
        // dan WAJIB kosong untuk status yang belum pernah disetujui
        // (DRAFT, CANCELLED).
        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_assignments
                ADD CONSTRAINT chk_compensation_assignments_approval_fields
                CHECK (
                    (
                        status IN ('APPROVED', 'ENDED', 'SUPERSEDED')
                        AND approved_by_membership_id IS NOT NULL
                        AND approved_at IS NOT NULL
                    )
                    OR
                    (
                        status IN ('DRAFT', 'CANCELLED')
                        AND approved_by_membership_id IS NULL
                        AND approved_at IS NULL
                    )
                )
                SQL,
        );

        // §7.4 — overlap APPROVED, varian TANPA position-assignment
        // scope (employment_position_assignment_id NULL).
        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_assignments
                ADD CONSTRAINT excl_compensation_assignments_approved_overlap_unscoped
                EXCLUDE USING gist (
                    tenant_id WITH =,
                    employment_id WITH =,
                    compensation_component_id WITH =,
                    daterange(effective_from, effective_to, '[]') WITH &&
                )
                WHERE (status = 'APPROVED' AND employment_position_assignment_id IS NULL)
                SQL,
        );

        // §7.4 — overlap APPROVED, varian DENGAN position-assignment
        // scope (employment_position_assignment_id terisi).
        DB::statement(
            <<<'SQL'
                ALTER TABLE compensation_assignments
                ADD CONSTRAINT excl_compensation_assignments_approved_overlap_scoped
                EXCLUDE USING gist (
                    tenant_id WITH =,
                    employment_id WITH =,
                    compensation_component_id WITH =,
                    employment_position_assignment_id WITH =,
                    daterange(effective_from, effective_to, '[]') WITH &&
                )
                WHERE (status = 'APPROVED' AND employment_position_assignment_id IS NOT NULL)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_assignments');
    }
};
