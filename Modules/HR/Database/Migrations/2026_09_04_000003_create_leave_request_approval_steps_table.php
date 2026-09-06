<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.9 — "Snapshot/runtime approval instance generated when
 * request is submitted."
 *
 * "Only the current actionable step can be approved/rejected. Later
 * steps remain pending but not actionable until earlier steps are
 * approved." — INV-HR-LEAVE-006 (Approval is sequential), ditegakkan
 * di service layer (Fase D Step berikutnya), bukan di sini.
 *
 * `policy_step_id` adalah SNAPSHOT referensi historis ke langkah
 * kebijakan asal — persis pola yang sama seperti
 * onboarding_tasks.template_task_id: FK simple, bukan composite,
 * karena tujuannya jejak asal-usul, bukan live-join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_approval_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('leave_request_id');
            $table->uuid('policy_step_id');
            $table->smallInteger('step_order');
            $table->string('required_permission', 120);
            $table->string('scope_strategy', 30);
            $table->boolean('independent_approver');
            $table->string('status', 20)->default('PENDING');
            $table->uuid('decided_by_membership_id')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['leave_request_id', 'step_order'],
                'uq_leave_request_approval_steps_order',
            );

            $table->foreign(
                ['leave_request_id', 'tenant_id'],
                'fk_leave_request_approval_steps_request_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_requests')
                ->cascadeOnDelete();

            // Simple FK — snapshot referensi historis (lihat catatan di
            // atas), konsistensi terhadap policy version yang sama
            // ditegakkan di service layer.
            $table->foreign(
                'policy_step_id',
                'fk_leave_request_approval_steps_policy_step',
            )
                ->references('id')
                ->on('leave_approval_policy_steps')
                ->restrictOnDelete();

            $table->foreign(
                'decided_by_membership_id',
                'fk_leave_request_approval_steps_decided_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_request_approval_steps
                ADD CONSTRAINT chk_leave_request_approval_steps_order_positive
                CHECK (step_order > 0)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_request_approval_steps
                ADD CONSTRAINT chk_leave_request_approval_steps_status
                CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'SKIPPED'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_approval_steps');
    }
};
