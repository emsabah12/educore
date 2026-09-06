<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.5 — "Versioned Leave approval configuration."
 *
 * "Once referenced by a submitted request, a policy version and its
 * steps are immutable. Changes create the next version." — `version_no`
 * MONOTONIK per `policy_code`; penegakan immutability sesungguhnya ada
 * di service layer (langkah berikutnya), migrasi ini murni struktur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('policy_code', 50);
            $table->integer('version_no');
            $table->string('name', 150);
            $table->uuid('leave_type_id')->nullable();
            $table->uuid('organization_id')->nullable();
            $table->uuid('organization_unit_id')->nullable();
            $table->uuid('employment_type_id')->nullable();
            $table->uuid('employment_classification_id')->nullable();
            $table->string('decision_mode', 20);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'policy_code', 'version_no'],
                'uq_leave_approval_policies_code_version',
            );

            // Supporting key untuk composite FK dari
            // leave_approval_policy_steps dan leave_requests.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_leave_approval_policies_id_tenant',
            );

            $table->index(
                ['tenant_id', 'policy_code', 'is_active'],
                'idx_leave_approval_policies_lookup',
            );

            $table->foreign(
                ['leave_type_id', 'tenant_id'],
                'fk_leave_approval_policies_leave_type_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_types')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'tenant_id'],
                'fk_leave_approval_policies_organization_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();

            // organization_units butuh tuple 3-kolom (id, organization_id,
            // tenant_id) — BUKAN 2-kolom seperti organizations.
            $table->foreign(
                ['organization_unit_id', 'organization_id', 'tenant_id'],
                'fk_leave_approval_policies_org_unit_tenant',
            )
                ->references(['id', 'organization_id', 'tenant_id'])
                ->on('organization_units')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_type_id', 'tenant_id'],
                'fk_leave_approval_policies_employment_type_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employment_types')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_classification_id', 'tenant_id'],
                'fk_leave_approval_policies_employment_class_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employment_classifications')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_approval_policies
                ADD CONSTRAINT chk_leave_approval_policies_decision_mode
                CHECK (decision_mode IN ('SEQUENTIAL', 'AUTO'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_approval_policies
                ADD CONSTRAINT chk_leave_approval_policies_version_positive
                CHECK (version_no > 0)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_approval_policies
                ADD CONSTRAINT chk_leave_approval_policies_effective_range
                CHECK (effective_to IS NULL OR effective_to >= effective_from)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_approval_policies
                ADD CONSTRAINT chk_leave_approval_policies_unit_requires_org
                CHECK (organization_unit_id IS NULL OR organization_id IS NOT NULL)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_policies');
    }
};
