<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.8 — "Explicit evaluator evidence for one selection stage."
 *
 * "Phase 2B does not introduce a generic dynamic form engine.
 * Rubric-specific sub-scores can be added later if actually required."
 * — desain SENGAJA sederhana (skor tunggal + rekomendasi), bukan form
 * builder generik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('application_stage_id');
            $table->uuid('evaluator_membership_id');
            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->string('recommendation', 10)->nullable();
            $table->text('remarks')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestamps();

            $table->index(
                'application_stage_id',
                'idx_recruitment_evaluations_application_stage',
            );

            $table->foreign(
                ['application_stage_id', 'tenant_id'],
                'fk_recruitment_evaluations_application_stage_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_application_stages')
                ->cascadeOnDelete();

            $table->foreign(
                'evaluator_membership_id',
                'fk_recruitment_evaluations_evaluator_membership',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_evaluations
                ADD CONSTRAINT chk_recruitment_evaluations_recommendation
                CHECK (recommendation IS NULL OR recommendation IN ('PASS', 'FAIL', 'HOLD'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_evaluations
                ADD CONSTRAINT chk_recruitment_evaluations_score_within_max
                CHECK (
                    score IS NULL
                    OR max_score IS NULL
                    OR score <= max_score
                )
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_evaluations');
    }
};
