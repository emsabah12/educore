<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.13 — "Immutable-ish task snapshot copied from template."
 *
 * "Changing a template never rewrites an existing onboarding case."
 * Persis pola yang sama seperti recruitment_application_stages: seluruh
 * kolom snapshot (title, category, sequence, is_required,
 * requires_evidence) DISALIN pada saat kasus dibuat, bukan live-join ke
 * OnboardingTemplateTask. `template_task_id` cuma referensi asal-usul
 * historis, bukan sumber kebenaran aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('onboarding_case_id');
            $table->uuid('template_task_id')->nullable();
            $table->string('code', 50);
            $table->string('title', 255);
            $table->string('category', 20);
            $table->unsignedInteger('sequence');
            $table->boolean('is_required')->default(true);
            $table->boolean('requires_evidence')->default(false);
            $table->string('status', 20)->default('PENDING');
            $table->uuid('completed_by_membership_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();

            $table->unique(
                ['onboarding_case_id', 'code'],
                'uq_onboarding_tasks_case_code',
            );

            $table->index(
                ['onboarding_case_id', 'status', 'sequence'],
                'idx_onboarding_tasks_case_status_sequence',
            );

            $table->foreign(
                ['onboarding_case_id', 'tenant_id'],
                'fk_onboarding_tasks_case_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('onboarding_cases')
                ->cascadeOnDelete();

            // Simple FK — snapshot referensi historis, bukan live-join
            // (lihat catatan di atas). Konsistensi terhadap template
            // yang sama ditegakkan di service layer.
            $table->foreign(
                'template_task_id',
                'fk_onboarding_tasks_template_task',
            )
                ->references('id')
                ->on('onboarding_template_tasks')
                ->nullOnDelete();

            $table->foreign(
                'completed_by_membership_id',
                'fk_onboarding_tasks_completed_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE onboarding_tasks
                ADD CONSTRAINT chk_onboarding_tasks_category
                CHECK (category IN ('DOCUMENT', 'ORIENTATION', 'CONTRACT', 'ADMIN'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE onboarding_tasks
                ADD CONSTRAINT chk_onboarding_tasks_status
                CHECK (status IN ('PENDING', 'COMPLETED', 'WAIVED'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE onboarding_tasks
                ADD CONSTRAINT chk_onboarding_tasks_completion_consistency
                CHECK (
                    (status = 'PENDING' AND completed_at IS NULL)
                    OR (status IN ('COMPLETED', 'WAIVED') AND completed_at IS NOT NULL)
                )
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
    }
};
