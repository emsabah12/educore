<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-004 §7.6 — Langkah approval milik satu versi kebijakan.
 *
 * "`required_permission` is a stable capability code, not a Position
 * name." — jangan pernah menyimpan nama Position/jabatan di sini,
 * hanya kode permission (mis. `hr.leave.approve`).
 *
 * Tabel ini TIDAK PUNYA `updated_at` — sekali sebuah versi kebijakan
 * dirujuk oleh Application yang sudah submit, langkah-langkahnya
 * immutable (§7.5); tidak ada baris yang pernah "diperbarui".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_policy_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('approval_policy_id');
            $table->smallInteger('step_order');
            $table->string('required_permission', 120);
            $table->string('scope_strategy', 30);
            $table->boolean('independent_approver')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['approval_policy_id', 'step_order'],
                'uq_leave_approval_policy_steps_order',
            );

            $table->foreign(
                ['approval_policy_id', 'tenant_id'],
                'fk_leave_approval_policy_steps_policy_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('leave_approval_policies')
                ->cascadeOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_approval_policy_steps
                ADD CONSTRAINT chk_leave_approval_policy_steps_order_positive
                CHECK (step_order > 0)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE leave_approval_policy_steps
                ADD CONSTRAINT chk_leave_approval_policy_steps_scope_strategy
                CHECK (scope_strategy IN ('REQUEST_PLACEMENT', 'ORGANIZATION', 'TENANT'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_policy_steps');
    }
};
