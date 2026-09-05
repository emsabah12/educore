<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.12 — "One onboarding process for one successful Application."
 *
 * `employee_id`/`employment_id` SENGAJA nullable — "populated only by
 * successful canonical hire conversion" (Fase E, belum dibangun). Kasus
 * onboarding di fase ini bisa dimulai dan berjalan sampai
 * READY_FOR_ACTIVATION TANPA Employee/Employment sama sekali — hanya
 * "Employment Activation" (§13) yang butuh kedua kolom ini terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('application_id');
            $table->uuid('template_id')->nullable();
            $table->uuid('employee_id')->nullable();
            $table->uuid('employment_id')->nullable();
            $table->string('status', 24)->default('NOT_STARTED');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            // "One onboarding process for one successful Application."
            $table->unique(
                ['application_id', 'tenant_id'],
                'uq_onboarding_cases_application_tenant',
            );

            // Supporting key untuk composite FK dari onboarding_tasks.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_onboarding_cases_id_tenant',
            );

            $table->index(
                ['tenant_id', 'status'],
                'idx_onboarding_cases_tenant_status',
            );

            $table->foreign(
                ['application_id', 'tenant_id'],
                'fk_onboarding_cases_application_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_applications')
                ->restrictOnDelete();

            $table->foreign(
                ['template_id', 'tenant_id'],
                'fk_onboarding_cases_template_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('onboarding_templates')
                ->restrictOnDelete();

            $table->foreign(
                ['employee_id', 'tenant_id'],
                'fk_onboarding_cases_employee_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employees')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_onboarding_cases_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE onboarding_cases
                ADD CONSTRAINT chk_onboarding_cases_status
                CHECK (status IN (
                    'NOT_STARTED',
                    'IN_PROGRESS',
                    'READY_FOR_ACTIVATION',
                    'COMPLETED',
                    'CANCELLED'
                ))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_cases');
    }
};
