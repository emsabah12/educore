<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.7 — "Concrete stage execution per Application."
 *
 * "Stage records should be instantiated from Vacancy stage configuration
 * at application submission so future Vacancy-stage edits do not
 * silently rewrite the applicant's historical path." Karena itu
 * `vacancy_stage_id` di sini adalah SALINAN referensi pada saat
 * submission — bukan live-join ke konfigurasi Vacancy yang mungkin
 * berubah belakangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_application_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('application_id');
            $table->uuid('vacancy_stage_id');
            $table->string('status', 20)->default('PENDING');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by_membership_id')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(
                ['application_id', 'vacancy_stage_id'],
                'uq_recruitment_application_stages_application_stage',
            );

            $table->index(
                ['application_id', 'status'],
                'idx_recruitment_application_stages_application_status',
            );

            $table->foreign(
                ['application_id', 'tenant_id'],
                'fk_recruitment_application_stages_application_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_applications')
                ->cascadeOnDelete();

            // Simple FK — vacancy_stage_id adalah snapshot referensi ke
            // konfigurasi Vacancy pada saat submission (lihat catatan di
            // atas); konsistensi terhadap Vacancy yang sama ditegakkan
            // di service layer, bukan composite FK, mengikuti pola yang
            // sudah dipakai untuk employees.membership_id.
            $table->foreign(
                'vacancy_stage_id',
                'fk_recruitment_application_stages_vacancy_stage',
            )
                ->references('id')
                ->on('recruitment_vacancy_stages')
                ->restrictOnDelete();

            $table->foreign(
                'completed_by_membership_id',
                'fk_recruitment_application_stages_completed_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_application_stages
                ADD CONSTRAINT chk_recruitment_application_stages_status
                CHECK (status IN (
                    'PENDING',
                    'IN_PROGRESS',
                    'PASSED',
                    'FAILED',
                    'SKIPPED'
                ))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_stages');
    }
};
