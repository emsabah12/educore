<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.6 — "Candidate × Vacancy lifecycle."
 *
 * INV-REC-002 (LOCKED) — "One Application per Candidate per Vacancy":
 * ditegakkan lewat UNIQUE(vacancy_id, candidate_id) di level database
 * — "a second application to the same Vacancy must not create another
 * application row" (§7.6, kata demi kata).
 *
 * State machine (§8.2):
 *     SUBMITTED -> IN_PROCESS -> {REJECTED, WITHDRAWN, HIRING_APPROVED}
 *     HIRING_APPROVED -> HIRED (setelah hire conversion berhasil,
 *     Fase E — belum dibangun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vacancy_id');
            $table->uuid('candidate_id');
            $table->string('status', 24)->default('SUBMITTED');
            $table->timestampTz('submitted_at');
            $table->timestampTz('finalized_at')->nullable();
            $table->timestamps();

            // INV-REC-002 — jantung dari tabel ini.
            $table->unique(
                ['vacancy_id', 'candidate_id'],
                'uq_recruitment_applications_vacancy_candidate',
            );

            // Supporting key untuk composite FK dari
            // recruitment_application_stages & recruitment_hiring_decisions.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_recruitment_applications_id_tenant',
            );

            $table->index(
                ['tenant_id', 'status'],
                'idx_recruitment_applications_tenant_status',
            );
            $table->index(
                ['candidate_id', 'status'],
                'idx_recruitment_applications_candidate_status',
            );

            $table->foreign(
                ['vacancy_id', 'tenant_id'],
                'fk_recruitment_applications_vacancy_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_vacancies')
                ->restrictOnDelete();

            $table->foreign(
                ['candidate_id', 'tenant_id'],
                'fk_recruitment_applications_candidate_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_candidates')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_applications
                ADD CONSTRAINT chk_recruitment_applications_status
                CHECK (status IN (
                    'SUBMITTED',
                    'IN_PROCESS',
                    'HIRING_APPROVED',
                    'REJECTED',
                    'WITHDRAWN',
                    'HIRED'
                ))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_applications
                ADD CONSTRAINT chk_recruitment_applications_finalized_status
                CHECK (
                    finalized_at IS NULL
                    OR status IN ('REJECTED', 'WITHDRAWN', 'HIRED')
                )
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_applications');
    }
};
