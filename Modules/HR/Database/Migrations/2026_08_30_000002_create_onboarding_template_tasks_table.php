<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.11 — `onboarding_template_tasks`.
 *
 * "Document binary persistence is not defined by Phase 2B. A DOCUMENT
 * task is a business requirement/checkpoint, not a new storage
 * subsystem." — kolom `category` (DOCUMENT/ORIENTATION/CONTRACT/ADMIN)
 * murni klasifikasi checklist; tidak ada upload file di fase ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_template_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('template_id');
            $table->string('code', 50);
            $table->string('title', 255);
            $table->string('category', 20);
            $table->unsignedInteger('sequence');
            $table->boolean('is_required')->default(true);
            $table->boolean('requires_evidence')->default(false);
            $table->timestamps();

            $table->unique(
                ['template_id', 'code'],
                'uq_onboarding_template_tasks_template_code',
            );
            $table->unique(
                ['template_id', 'sequence'],
                'uq_onboarding_template_tasks_template_sequence',
            );

            $table->foreign(
                ['template_id', 'tenant_id'],
                'fk_onboarding_template_tasks_template_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('onboarding_templates')
                ->cascadeOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE onboarding_template_tasks
                ADD CONSTRAINT chk_onboarding_template_tasks_category
                CHECK (category IN ('DOCUMENT', 'ORIENTATION', 'CONTRACT', 'ADMIN'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_template_tasks');
    }
};
