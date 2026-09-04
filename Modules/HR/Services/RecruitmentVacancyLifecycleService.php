<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Models\RecruitmentVacancyDecision;

/**
 * Implementasi state machine Vacancy dari HR-003 §8.1:
 *
 *     DRAFT --submit--> PENDING_APPROVAL --approve--> APPROVED
 *     PENDING_APPROVAL --reject--> DRAFT (+ decision evidence)
 *     APPROVED --open--> OPEN --close--> CLOSED
 *     DRAFT / APPROVED / OPEN --cancel--> CANCELLED
 *
 * PENTING: `reject()` TIDAK membawa Vacancy ke status "REJECTED"
 * tersendiri — sesuai diagram §8.1, reject mengembalikannya ke DRAFT
 * supaya bisa direvisi dan diajukan ulang. Bukti bahwa Vacancy ini
 * pernah ditolak tetap ada, tapi lewat baris RecruitmentVacancyDecision
 * (§7.2), bukan lewat status.
 */
final readonly class RecruitmentVacancyLifecycleService
{
    /**
     * @param array{
     *     code: string,
     *     title: string,
     *     position_id: string,
     *     organization_id: string,
     *     organization_unit_id?: string|null,
     *     requested_headcount: int,
     *     description?: string|null,
     *     created_by_membership_id: string,
     * } $data
     */
    public function createDraft(string $tenantId, array $data): RecruitmentVacancy
    {
        return DB::transaction(function () use ($tenantId, $data): RecruitmentVacancy {
            $this->requireActivePosition($data['position_id'], $tenantId);
            $this->requireActiveOrganization($data['organization_id'], $tenantId);

            $organizationUnitId = $data['organization_unit_id'] ?? null;

            if ($organizationUnitId !== null) {
                $this->requireActiveOrganizationUnit(
                    $organizationUnitId,
                    $data['organization_id'],
                    $tenantId,
                );
            }

            return RecruitmentVacancy::create([
                'code' => $data['code'],
                'title' => $data['title'],
                'position_id' => $data['position_id'],
                'organization_id' => $data['organization_id'],
                'organization_unit_id' => $organizationUnitId,
                'requested_headcount' => $data['requested_headcount'],
                'description' => $data['description'] ?? null,
                'created_by_membership_id' => $data['created_by_membership_id'],
            ]);
        });
    }

    public function submit(string $tenantId, string $vacancyId): RecruitmentVacancy
    {
        return DB::transaction(function () use ($tenantId, $vacancyId): RecruitmentVacancy {
            $vacancy = $this->lockVacancyForTenant($vacancyId, $tenantId);

            $this->requireStatus(
                $vacancy,
                [RecruitmentVacancy::STATUS_DRAFT],
                'submitted',
            );

            $vacancy->status = RecruitmentVacancy::STATUS_PENDING_APPROVAL;
            $vacancy->save();

            return $vacancy->refresh();
        });
    }

    public function approve(
        string $tenantId,
        string $vacancyId,
        string $decidedByMembershipId,
        ?string $reason = null,
    ): RecruitmentVacancy {
        return DB::transaction(function () use (
            $tenantId,
            $vacancyId,
            $decidedByMembershipId,
            $reason,
        ): RecruitmentVacancy {
            $vacancy = $this->lockVacancyForTenant($vacancyId, $tenantId);

            $this->requireStatus(
                $vacancy,
                [RecruitmentVacancy::STATUS_PENDING_APPROVAL],
                'approved',
            );

            $vacancy->status = RecruitmentVacancy::STATUS_APPROVED;
            $vacancy->save();

            RecruitmentVacancyDecision::create([
                'vacancy_id' => $vacancy->id,
                'decision' => RecruitmentVacancyDecision::DECISION_APPROVED,
                'decided_by_membership_id' => $decidedByMembershipId,
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            return $vacancy->refresh();
        });
    }

    public function reject(
        string $tenantId,
        string $vacancyId,
        string $decidedByMembershipId,
        ?string $reason = null,
    ): RecruitmentVacancy {
        return DB::transaction(function () use (
            $tenantId,
            $vacancyId,
            $decidedByMembershipId,
            $reason,
        ): RecruitmentVacancy {
            $vacancy = $this->lockVacancyForTenant($vacancyId, $tenantId);

            $this->requireStatus(
                $vacancy,
                [RecruitmentVacancy::STATUS_PENDING_APPROVAL],
                'rejected',
            );

            // §8.1: reject -> DRAFT (bukan status "REJECTED" tersendiri).
            $vacancy->status = RecruitmentVacancy::STATUS_DRAFT;
            $vacancy->save();

            RecruitmentVacancyDecision::create([
                'vacancy_id' => $vacancy->id,
                'decision' => RecruitmentVacancyDecision::DECISION_REJECTED,
                'decided_by_membership_id' => $decidedByMembershipId,
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            return $vacancy->refresh();
        });
    }

    public function open(string $tenantId, string $vacancyId): RecruitmentVacancy
    {
        return DB::transaction(function () use ($tenantId, $vacancyId): RecruitmentVacancy {
            $vacancy = $this->lockVacancyForTenant($vacancyId, $tenantId);

            $this->requireStatus(
                $vacancy,
                [RecruitmentVacancy::STATUS_APPROVED],
                'opened',
            );

            $vacancy->status = RecruitmentVacancy::STATUS_OPEN;
            $vacancy->open_at = now();
            $vacancy->save();

            return $vacancy->refresh();
        });
    }

    public function close(string $tenantId, string $vacancyId): RecruitmentVacancy
    {
        return DB::transaction(function () use ($tenantId, $vacancyId): RecruitmentVacancy {
            $vacancy = $this->lockVacancyForTenant($vacancyId, $tenantId);

            $this->requireStatus(
                $vacancy,
                [RecruitmentVacancy::STATUS_OPEN],
                'closed',
            );

            $vacancy->status = RecruitmentVacancy::STATUS_CLOSED;
            $vacancy->close_at = now();
            $vacancy->save();

            return $vacancy->refresh();
        });
    }

    public function cancel(string $tenantId, string $vacancyId): RecruitmentVacancy
    {
        return DB::transaction(function () use ($tenantId, $vacancyId): RecruitmentVacancy {
            $vacancy = $this->lockVacancyForTenant($vacancyId, $tenantId);

            $this->requireStatus(
                $vacancy,
                [
                    RecruitmentVacancy::STATUS_DRAFT,
                    RecruitmentVacancy::STATUS_APPROVED,
                    RecruitmentVacancy::STATUS_OPEN,
                ],
                'cancelled',
            );

            $vacancy->status = RecruitmentVacancy::STATUS_CANCELLED;
            $vacancy->save();

            return $vacancy->refresh();
        });
    }

    /**
     * @param list<string> $allowedStatuses
     */
    private function requireStatus(
        RecruitmentVacancy $vacancy,
        array $allowedStatuses,
        string $actionLabel,
    ): void {
        if (! in_array($vacancy->status, $allowedStatuses, true)) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Vacancy [%s] cannot be %s from status [%s].',
                    $vacancy->id,
                    $actionLabel,
                    $vacancy->status,
                ),
            );
        }
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

    private function requireActivePosition(string $positionId, string $tenantId): void
    {
        $isActive = DB::table('positions')
            ->where('id', $positionId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $isActive) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Position [%s] does not reference an active catalog entry in tenant [%s].',
                    $positionId,
                    $tenantId,
                ),
            );
        }
    }

    private function requireActiveOrganization(string $organizationId, string $tenantId): void
    {
        $isActive = DB::table('organizations')
            ->where('id', $organizationId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $isActive) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Organization [%s] is not active in tenant [%s].',
                    $organizationId,
                    $tenantId,
                ),
            );
        }
    }

    private function requireActiveOrganizationUnit(
        string $organizationUnitId,
        string $organizationId,
        string $tenantId,
    ): void {
        $isActive = DB::table('organization_units')
            ->where('id', $organizationUnitId)
            ->where('organization_id', $organizationId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $isActive) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'OrganizationUnit [%s] is not an active unit of Organization [%s].',
                    $organizationUnitId,
                    $organizationId,
                ),
            );
        }
    }
}
