<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-002 §5.6 — Employment Placement.
 *
 * "This table is HR history, not a replacement for Core
 * organizational_assignments." Placement HANYA mencatat kapan Employment
 * ini "menempel" pada sebuah Core OrganizationalAssignment yang sudah
 * ada — tabel ini tidak pernah memiliki data organisasi sendiri
 * (tidak ada organization_id/organization_unit_id di sini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employment_id');
            $table->uuid('organizational_assignment_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // Supporting key untuk composite FK dari
            // employment_position_assignments (Step berikutnya):
            // (employment_placement_id, employment_id, tenant_id).
            $table->unique(
                ['id', 'employment_id', 'tenant_id'],
                'uq_employment_placements_id_employment_tenant',
            );

            $table->index(
                ['employment_id', 'effective_to'],
                'idx_employment_placements_employment_open',
            );
            $table->index(
                ['tenant_id', 'organizational_assignment_id', 'effective_to'],
                'idx_employment_placements_assignment_open',
            );

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_employment_placements_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                ['organizational_assignment_id', 'tenant_id'],
                'fk_employment_placements_assignment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('organizational_assignments')
                ->restrictOnDelete();
        });

        // ---------------------------------------------------------------
        // "effective_to IS NULL" artinya baris ini adalah placement yang
        // SEDANG BERJALAN (open/current). Tiga guard di bawah semuanya
        // partial index yang hanya berlaku untuk baris open — riwayat
        // yang sudah ditutup (effective_to terisi) bebas dari batasan
        // ini, karena riwayat lama boleh menumpuk sebanyak apa pun.
        // ---------------------------------------------------------------

        // §9.2 langkah 8 — "reject duplicate open placement": satu
        // Employment tidak boleh punya dua placement TERBUKA yang
        // menunjuk ke Core Assignment yang sama.
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_employment_placements_open_assignment
                ON employment_placements (tenant_id, employment_id, organizational_assignment_id)
                WHERE effective_to IS NULL
                SQL,
        );

        // INV-HR-009 — "never more than one open primary placement".
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_employment_placements_open_primary
                ON employment_placements (tenant_id, employment_id)
                WHERE is_primary = true AND effective_to IS NULL
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE employment_placements
                ADD CONSTRAINT chk_employment_placements_effective_to_after_from
                CHECK (effective_to IS NULL OR effective_to >= effective_from)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_placements');
    }
};
