<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * PostgreSQL requires every referenced composite FK target to have
         * an exact unique/primary constraint. Membership identity remains
         * canonical Person × Tenant; this supporting constraint does not
         * change that ownership or cardinality.
         */
        Schema::table('memberships', function (Blueprint $table): void {
            $table->unique(
                ['id', 'tenant_id'],
                'uq_memberships_id_tenant',
            );
        });

        Schema::create('organizational_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id');
            $table->uuid('membership_id');
            $table->uuid('organization_id');
            $table->uuid('organization_unit_id')->nullable();

            $table->string('status', 20)
                ->default('ACTIVE');

            $table->timestamps();

            $table->index(
                ['tenant_id', 'status'],
                'idx_org_assignments_tenant_status',
            );

            $table->index(
                ['membership_id', 'status'],
                'idx_org_assignments_membership_status',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            /*
             * Membership and Assignment must belong to the same Tenant.
             */
            $table->foreign(
                ['membership_id', 'tenant_id'],
                'fk_org_assignments_membership_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('memberships')
                ->restrictOnDelete();

            /*
             * Organization and Assignment must belong to the same Tenant.
             */
            $table->foreign(
                ['organization_id', 'tenant_id'],
                'fk_org_assignments_organization_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();

            /*
             * When organization_unit_id is present, the Unit must belong
             * to both the selected Organization and the same Tenant.
             *
             * PostgreSQL MATCH SIMPLE semantics intentionally allow this
             * composite FK when organization_unit_id is NULL, representing
             * an organization-level assignment.
             */
            $table->foreign(
                [
                    'organization_unit_id',
                    'organization_id',
                    'tenant_id',
                ],
                'fk_org_assignments_unit_scope',
            )
                ->references([
                    'id',
                    'organization_id',
                    'tenant_id',
                ])
                ->on('organization_units')
                ->restrictOnDelete();
        });

        /*
         * Organization-level assignments:
         * one Membership cannot be assigned twice to the same Organization
         * with organization_unit_id = NULL.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX uq_org_assignments_membership_organization
            ON organizational_assignments (membership_id, organization_id)
            WHERE organization_unit_id IS NULL
            SQL,
        );

        /*
         * Unit-level assignments:
         * one Membership cannot be assigned twice to the same Unit.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX uq_org_assignments_membership_unit
            ON organizational_assignments (membership_id, organization_unit_id)
            WHERE organization_unit_id IS NOT NULL
            SQL,
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE organizational_assignments
            ADD CONSTRAINT chk_org_assignments_status
            CHECK (status IN ('ACTIVE', 'INACTIVE'))
            SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_assignments');

        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('uq_memberships_id_tenant');
        });
    }
};
