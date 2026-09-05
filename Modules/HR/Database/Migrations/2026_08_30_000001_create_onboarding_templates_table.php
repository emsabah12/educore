<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.10 — "Reusable tenant onboarding checklist template."
 *
 * "No Position/RBAC permission is embedded into the template" — template
 * ini murni daftar tugas administratif (dokumen, orientasi, kontrak,
 * dst), TIDAK menentukan siapa yang boleh apa. Otorisasi tetap lewat
 * permission `hr.onboarding.*` di HTTP layer (Step 4), bukan dari data
 * template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'code'],
                'uq_onboarding_templates_tenant_code',
            );

            // Supporting key untuk composite FK dari
            // onboarding_template_tasks & onboarding_cases.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_onboarding_templates_id_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_onboarding_templates_tenant_active',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_templates');
    }
};
