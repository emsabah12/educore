<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentApplicationStage;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Models\RecruitmentVacancyStage;

/**
 * Implementasi state machine Application dari HR-003 §8.2:
 *
 *     SUBMITTED --startProcessing--> IN_PROCESS
 *     IN_PROCESS --reject--> REJECTED
 *     IN_PROCESS --withdraw--> WITHDRAWN
 *     SUBMITTED --withdraw--> WITHDRAWN
 *     IN_PROCESS --approveForHiring--> HIRING_APPROVED
 *
 * "HIRING_APPROVED does not mean Employment is active" — transisi ke
 * HIRED hanya terjadi lewat hire conversion (Fase E, belum dibangun).
 * Karena itu HIRING_APPROVED SENGAJA tidak mengisi `finalized_at`
 * (lihat CHECK constraint migration) — dia bukan status akhir.
 */
final readonly class RecruitmentApplicationLifecycleService
{
    /**
     * HR-003 §8.1: "A Vacancy may only receive new Applications while
     * OPEN." §7.7: stage snapshot diambil dari konfigurasi Vacancy
     * PERSIS pada saat submission, supaya perubahan konfigurasi
     * Vacancy belakangan tidak menulis ulang riwayat pelamar yang
     * sudah ada.
     */
    public function submitApplication(
        string $tenantId,
        string $vacancyId,
        string $candidateId,
    ): RecruitmentApplication {
        return DB::transaction(function () use (
            $tenantId,
            $vacancyId,
            $candidateId,
        ): RecruitmentApplication {
            $vacancy = $this->lockVacancyForTenant($vacancyId, $tenantId);

            if ($vacancy->status !== RecruitmentVacancy::STATUS_OPEN) {
                throw new RecruitmentLifecycleException(
                    sprintf(
                        'Vacancy [%s] is not accepting new Applications (status [%s]).',
                        $vacancyId,
                        $vacancy->status,
                    ),
                );
            }

            $this->requireCandidateExists($candidateId, $tenantId);

            try {
                $application = RecruitmentApplication::create([
                    'vacancy_id' => $vacancyId,
                    'candidate_id' => $candidateId,
                    'submitted_at' => now(),
                ]);
            } catch (QueryException $exception) {
                // INV-REC-002 — UNIQUE(vacancy_id, candidate_id) di
                // database adalah penjaga integritas terakhir.
                throw new RecruitmentLifecycleException(
                    sprintf(
                        'Candidate [%s] has already applied to Vacancy [%s].',
                        $candidateId,
                        $vacancyId,
                    ),
                    previous: $exception,
                );
            }

            /** @var RecruitmentVacancyStage $vacancyStage */
            foreach (
                $vacancy->stages()->where('is_active', true)->orderBy('sequence')->get()
                as $vacancyStage
            ) {
                RecruitmentApplicationStage::create([
                    'application_id' => $application->id,
                    'vacancy_stage_id' => $vacancyStage->id,
                ]);
            }

            return $application->refresh();
        });
    }

    public function startProcessing(string $tenantId, string $applicationId): RecruitmentApplication
    {
        return $this->transition(
            tenantId: $tenantId,
            applicationId: $applicationId,
            allowedStatuses: [RecruitmentApplication::STATUS_SUBMITTED],
            newStatus: RecruitmentApplication::STATUS_IN_PROCESS,
            actionLabel: 'moved to processing',
            isFinal: false,
        );
    }

    public function reject(string $tenantId, string $applicationId): RecruitmentApplication
    {
        return $this->transition(
            tenantId: $tenantId,
            applicationId: $applicationId,
            allowedStatuses: [RecruitmentApplication::STATUS_IN_PROCESS],
            newStatus: RecruitmentApplication::STATUS_REJECTED,
            actionLabel: 'rejected',
            isFinal: true,
        );
    }

    public function withdraw(string $tenantId, string $applicationId): RecruitmentApplication
    {
        return $this->transition(
            tenantId: $tenantId,
            applicationId: $applicationId,
            allowedStatuses: [
                RecruitmentApplication::STATUS_SUBMITTED,
                RecruitmentApplication::STATUS_IN_PROCESS,
            ],
            newStatus: RecruitmentApplication::STATUS_WITHDRAWN,
            actionLabel: 'withdrawn',
            isFinal: true,
        );
    }

    /**
     * "HIRING_APPROVED does not mean Employment is active" — SENGAJA
     * bukan status final (`isFinal: false`), karena Application masih
     * menunggu hire conversion (Fase E) untuk benar-benar berubah jadi
     * HIRED.
     */
    public function approveForHiring(string $tenantId, string $applicationId): RecruitmentApplication
    {
        return $this->transition(
            tenantId: $tenantId,
            applicationId: $applicationId,
            allowedStatuses: [RecruitmentApplication::STATUS_IN_PROCESS],
            newStatus: RecruitmentApplication::STATUS_HIRING_APPROVED,
            actionLabel: 'approved for hiring',
            isFinal: false,
        );
    }

    /**
     * @param list<string> $allowedStatuses
     */
    private function transition(
        string $tenantId,
        string $applicationId,
        array $allowedStatuses,
        string $newStatus,
        string $actionLabel,
        bool $isFinal,
    ): RecruitmentApplication {
        return DB::transaction(function () use (
            $tenantId,
            $applicationId,
            $allowedStatuses,
            $newStatus,
            $actionLabel,
            $isFinal,
        ): RecruitmentApplication {
            $application = $this->lockApplicationForTenant($applicationId, $tenantId);

            if (! in_array($application->status, $allowedStatuses, true)) {
                throw new RecruitmentLifecycleException(
                    sprintf(
                        'Application [%s] cannot be %s from status [%s].',
                        $application->id,
                        $actionLabel,
                        $application->status,
                    ),
                );
            }

            $application->status = $newStatus;

            if ($isFinal) {
                $application->finalized_at = now();
            }

            $application->save();

            return $application->refresh();
        });
    }

    private function lockVacancyForTenant(
        string $vacancyId,
        string $tenantId,
    ): RecruitmentVacancy {
        /** @var RecruitmentVacancy|null $vacancy */
        $vacancy = RecruitmentVacancy::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $vacancyId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($vacancy === null) {
            throw (new ModelNotFoundException())->setModel(
                RecruitmentVacancy::class,
                [$vacancyId],
            );
        }

        return $vacancy;
    }

    private function lockApplicationForTenant(
        string $applicationId,
        string $tenantId,
    ): RecruitmentApplication {
        /** @var RecruitmentApplication|null $application */
        $application = RecruitmentApplication::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $applicationId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($application === null) {
            throw (new ModelNotFoundException())->setModel(
                RecruitmentApplication::class,
                [$applicationId],
            );
        }

        return $application;
    }

    private function requireCandidateExists(
        string $candidateId,
        string $tenantId,
    ): void {
        $exists = RecruitmentCandidate::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $candidateId)
            ->where('tenant_id', $tenantId)
            ->where('status', RecruitmentCandidate::STATUS_ACTIVE)
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException())->setModel(
                RecruitmentCandidate::class,
                [$candidateId],
            );
        }
    }
}
