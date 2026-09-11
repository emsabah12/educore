<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-006 §7.6 — Employee Benefit Participation.
 *
 * Melacak eligibility/enrollment/participation, BUKAN settlement
 * moneter final (itu domain Finance). `beneficiary_person_id`
 * OPSIONAL: null berarti Employee sendiri yang jadi beneficiary;
 * terisi berarti dependent/Person lain (referensi Core `Person`
 * langsung — tidak ada duplikasi identitas anak/tanggungan di HR).
 *
 * §7.6 invariant "no overlapping active participation for same
 * Employment + Program + beneficiary" SENGAJA diimplementasikan
 * sebagai partial unique index untuk baris "open DAN berstatus
 * aktif" (effective_to IS NULL AND status IN ELIGIBLE/ENROLLED) —
 * BUKAN GiST date-range exclusion seperti compensation_assignments.
 * PRD di sini memakai kata "active" (merujuk status), bukan
 * "overlapping effective periods" (merujuk rentang tanggal) seperti
 * §7.4 — jadi pola "cegah duplikat baris terbuka" yang sudah dipakai
 * `employment_position_assignments`/`employment_placements` lebih
 * pas daripada exclusion constraint rentang tanggal penuh di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_benefit_participations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employment_id');
            $table->uuid('benefit_program_id');
            $table->uuid('beneficiary_person_id')->nullable();
            $table->string('status', 25)->default('ELIGIBLE');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('verified_by_membership_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'employment_id', 'status'],
                'idx_benefit_participations_employment_status',
            );
            $table->index(
                ['tenant_id', 'benefit_program_id', 'status'],
                'idx_benefit_participations_program_status',
            );

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_benefit_participations_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                ['benefit_program_id', 'tenant_id'],
                'fk_benefit_participations_program_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('benefit_programs')
                ->restrictOnDelete();

            // Person adalah entitas Core tenant-independent (bukan
            // BelongsToTenant) — FK sederhana, bukan komposit.
            $table->foreign(
                'beneficiary_person_id',
                'fk_benefit_participations_beneficiary',
            )
                ->references('id')
                ->on('persons')
                ->restrictOnDelete();

            $table->foreign(
                'verified_by_membership_id',
                'fk_benefit_participations_verified_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE employee_benefit_participations
                ADD CONSTRAINT chk_benefit_participations_status
                CHECK (status IN (
                    'ELIGIBLE',
                    'ENROLLED',
                    'SUSPENDED',
                    'ENDED',
                    'INELIGIBLE'
                ))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE employee_benefit_participations
                ADD CONSTRAINT chk_benefit_participations_effective_to_after_from
                CHECK (effective_to IS NULL OR effective_to >= effective_from)
                SQL,
        );

        // §7.6 — cegah duplikasi partisipasi TERBUKA + AKTIF, varian
        // TANPA beneficiary (beneficiary_person_id NULL berarti
        // Employee sendiri).
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_benefit_participations_open_active_self
                ON employee_benefit_participations (tenant_id, employment_id, benefit_program_id)
                WHERE effective_to IS NULL
                    AND beneficiary_person_id IS NULL
                    AND status IN ('ELIGIBLE', 'ENROLLED')
                SQL,
        );

        // §7.6 — varian DENGAN beneficiary (dependent/Person lain).
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_benefit_participations_open_active_dependent
                ON employee_benefit_participations (tenant_id, employment_id, benefit_program_id, beneficiary_person_id)
                WHERE effective_to IS NULL
                    AND beneficiary_person_id IS NOT NULL
                    AND status IN ('ELIGIBLE', 'ENROLLED')
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_benefit_participations');
    }
};
