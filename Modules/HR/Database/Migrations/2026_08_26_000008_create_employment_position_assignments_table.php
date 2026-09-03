<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-002 §5.7 — Employment Position Assignment.
 *
 * Menugaskan Position (katalog jabatan HR dari Step 2) ke sebuah
 * Employment. `employment_placement_id` OPSIONAL: kalau diisi, penugasan
 * ini terikat ke penempatan organisasi tertentu (mis. "Guru Matematika
 * DI unit tertentu"); kalau null, ini penugasan jabatan tingkat-tenant
 * yang tidak terikat organisasi manapun (mis. jabatan struktural
 * yayasan). "The nullable composite placement FK preserves tenant-level
 * positions without copying Organization/Unit ownership."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_position_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employment_id');
            $table->uuid('position_id');
            $table->uuid('employment_placement_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(
                ['employment_id', 'effective_to'],
                'idx_emp_position_assignments_employment_open',
            );
            $table->index(
                ['tenant_id', 'position_id', 'effective_to'],
                'idx_emp_position_assignments_position_open',
            );
            $table->index(
                ['employment_placement_id', 'effective_to'],
                'idx_emp_position_assignments_placement_open',
            );

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_emp_position_assignments_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                ['position_id', 'tenant_id'],
                'fk_emp_position_assignments_position_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('positions')
                ->restrictOnDelete();

            // Composite FK nullable: PostgreSQL MATCH SIMPLE semantics
            // membiarkan FK ini "diam" (tidak dicek) ketika
            // employment_placement_id NULL — merepresentasikan penugasan
            // jabatan tingkat-tenant. Ketika DIISI, FK tetap memaksa
            // Placement itu benar-benar milik Employment yang sama
            // (mencegah "menugaskan jabatan lewat placement milik
            // Employment lain").
            $table->foreign(
                ['employment_placement_id', 'employment_id', 'tenant_id'],
                'fk_emp_position_assignments_placement_employment_tenant',
            )
                ->references(['id', 'employment_id', 'tenant_id'])
                ->on('employment_placements')
                ->restrictOnDelete();
        });

        // ---------------------------------------------------------------
        // §6.3 — tiga partial unique index, semuanya hanya berlaku untuk
        // baris "open" (effective_to IS NULL). Riwayat yang sudah ditutup
        // bebas menumpuk tanpa batas.
        // ---------------------------------------------------------------

        // INV-HR-009 — max satu open primary position per Employment.
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_emp_position_assignments_open_primary
                ON employment_position_assignments (tenant_id, employment_id)
                WHERE is_primary = true AND effective_to IS NULL
                SQL,
        );

        // Cegah duplikasi: Position yang sama, Placement yang sama,
        // dua-duanya terbuka sekaligus.
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_emp_position_assignments_open_scoped
                ON employment_position_assignments (tenant_id, employment_id, position_id, employment_placement_id)
                WHERE effective_to IS NULL AND employment_placement_id IS NOT NULL
                SQL,
        );

        // Cegah duplikasi versi tenant-level (employment_placement_id
        // NULL) — perlu index TERPISAH dari yang di atas karena
        // PostgreSQL memperlakukan NULL sebagai "tidak sama dengan NULL
        // lainnya", sehingga UNIQUE biasa tidak akan menangkap duplikasi
        // baris ber-NULL.
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_emp_position_assignments_open_unscoped
                ON employment_position_assignments (tenant_id, employment_id, position_id)
                WHERE effective_to IS NULL AND employment_placement_id IS NULL
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE employment_position_assignments
                ADD CONSTRAINT chk_emp_position_assignments_effective_to_after_from
                CHECK (effective_to IS NULL OR effective_to >= effective_from)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_position_assignments');
    }
};
