<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.2 — "Defines fixed entitlement generation rules. This table
 * is policy configuration, not employee balance."
 *
 * Urutan seleksi kebijakan (§7.2, dijalankan di service layer, Step 3):
 *   1. efektif pada awal periode entitlement;
 *   2. Leave Type harus cocok;
 *   3. filter organization/unit harus cocok Employment Placement aktif;
 *   4. filter employment type/classification harus cocok Employment;
 *   5. spesifisitas scope lebih besar menang: unit > organization > tenant;
 *   6. spesifisitas employment-filter lebih besar menang;
 *   7. priority memutus kandidat non-equal yang sengaja dikonfigurasi;
 *   8. tie spesifisitas + priority yang sama = konflik konfigurasi (GAGAL
 *      eksplisit, TIDAK PERNAH memilih baris pertama secara acak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlement_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('leave_type_id');
            $table->uuid('organization_id')->nullable();
            $table->uuid('organization_unit_id')->nullable();
            $table->uuid('employment_type_id')->nullable();
            $table->uuid('employment_classification_id')->nullable();
            $table->string('period_basis', 30);
            $table->decimal('grant_units', 10, 2);
            $table->string('carryover_mode', 20)->default('NONE');
            $table->decimal('carryover_limit_units', 10, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Supporting key untuk composite FK dari leave_entitlements.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_leave_entitlement_policies_id_tenant',
            );

            $table->index(
                ['tenant_id', 'leave_type_id', 'is_active', 'effective_from', 'effective_to'],
                'idx_leave_entitlement_policies_lookup',
            );

            $table->foreign(
                ['leave_type_id', 'tenant_id'],
                'fk_leave_entitlement_policies_leave_type_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_types')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'tenant_id'],
                'fk_leave_entitlement_policies_organization_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_unit_id', 'organization_id', 'tenant_id'],
                'fk_leave_entitlement_policies_org_unit_tenant',
            )
                ->references(['id', 'organization_id', 'tenant_id'])
                ->on('organization_units')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_type_id', 'tenant_id'],
                'fk_leave_entitlement_policies_employment_type_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employment_types')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_classification_id', 'tenant_id'],
                'fk_leave_entitlement_policies_employment_class_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employment_classifications')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlement_policies
                ADD CONSTRAINT chk_leave_entitlement_policies_period_basis
                CHECK (period_basis IN ('CALENDAR_YEAR', 'EMPLOYMENT_ANNIVERSARY', 'MANUAL'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlement_policies
                ADD CONSTRAINT chk_leave_entitlement_policies_carryover_mode
                CHECK (carryover_mode IN ('NONE', 'LIMITED'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlement_policies
                ADD CONSTRAINT chk_leave_entitlement_policies_carryover_limit
                CHECK (
                    (carryover_mode = 'LIMITED' AND carryover_limit_units >= 0)
                    OR (carryover_mode = 'NONE' AND carryover_limit_units IS NULL)
                )
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlement_policies
                ADD CONSTRAINT chk_leave_entitlement_policies_grant_units
                CHECK (grant_units > 0)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlement_policies
                ADD CONSTRAINT chk_leave_entitlement_policies_effective_range
                CHECK (effective_to IS NULL OR effective_to >= effective_from)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_entitlement_policies
                ADD CONSTRAINT chk_leave_entitlement_policies_unit_requires_org
                CHECK (organization_unit_id IS NULL OR organization_id IS NOT NULL)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlement_policies');
    }
};
