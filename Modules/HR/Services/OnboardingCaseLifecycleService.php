<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\OnboardingLifecycleException;
use Modules\HR\Models\OnboardingCase;
use Modules\HR\Models\OnboardingTask;
use Modules\HR\Models\OnboardingTemplate;
use Modules\HR\Models\OnboardingTemplateTask;
use Modules\HR\Models\RecruitmentApplication;

/**
 * Implementasi state machine Onboarding Case dari HR-003 §8.3:
 *
 *     NOT_STARTED --startProgress--> IN_PROGRESS
 *     IN_PROGRESS --(semua tugas wajib selesai/waived, OTOMATIS)--> READY_FOR_ACTIVATION
 *     {NOT_STARTED, IN_PROGRESS} --cancel--> CANCELLED
 *
 * "READY_FOR_ACTIVATION" TIDAK dicapai lewat aksi manual — dia otomatis
 * terjadi begitu tugas wajib terakhir diselesaikan/diwaive
 * (`maybeAdvanceToReadyForActivation()`). Transisi ke `COMPLETED` baru
 * terjadi lewat orkestrasi aktivasi Employment (§13, Fase E/F — belum
 * dibangun di sini).
 */
final readonly class OnboardingCaseLifecycleService
{
    /**
     * Membuat Onboarding Case untuk sebuah Application. Kalau
     * `$templateId` diisi, seluruh tugas template DISALIN (snapshot,
     * §7.13) — perubahan template belakangan tidak menulis ulang kasus
     * yang sudah dibuat.
     */
    public function createCase(
        string $tenantId,
        string $applicationId,
        ?string $templateId = null,
    ): OnboardingCase {
        return DB::transaction(function () use (
            $tenantId,
            $applicationId,
            $templateId,
        ): OnboardingCase {
            $this->requireApplicationExists($applicationId, $tenantId);

            $template = null;

            if ($templateId !== null) {
                $template = $this->requireActiveTemplate($templateId, $tenantId);
            }

            try {
                $case = OnboardingCase::create([
                    'application_id' => $applicationId,
                    'template_id' => $templateId,
                ]);
            } catch (QueryException $exception) {
                throw new OnboardingLifecycleException(
                    sprintf(
                        'Application [%s] already has an Onboarding Case.',
                        $applicationId,
                    ),
                    previous: $exception,
                );
            }

            if ($template !== null) {
                /** @var OnboardingTemplateTask $templateTask */
                foreach ($template->tasks as $templateTask) {
                    OnboardingTask::create([
                        'onboarding_case_id' => $case->id,
                        'template_task_id' => $templateTask->id,
                        'code' => $templateTask->code,
                        'title' => $templateTask->title,
                        'category' => $templateTask->category,
                        'sequence' => $templateTask->sequence,
                        'is_required' => $templateTask->is_required,
                        'requires_evidence' => $templateTask->requires_evidence,
                    ]);
                }
            }

            return $case->refresh();
        });
    }

    public function startProgress(string $tenantId, string $caseId): OnboardingCase
    {
        return DB::transaction(function () use ($tenantId, $caseId): OnboardingCase {
            $case = $this->lockCaseForTenant($caseId, $tenantId);

            if ($case->status !== OnboardingCase::STATUS_NOT_STARTED) {
                throw new OnboardingLifecycleException(
                    sprintf(
                        'Onboarding Case [%s] cannot be started from status [%s].',
                        $case->id,
                        $case->status,
                    ),
                );
            }

            $case->status = OnboardingCase::STATUS_IN_PROGRESS;
            $case->started_at = now();
            $case->save();

            return $case->refresh();
        });
    }

    public function completeTask(
        string $tenantId,
        string $taskId,
        string $completedByMembershipId,
        ?string $note = null,
    ): OnboardingTask {
        return $this->finalizeTask(
            $tenantId,
            $taskId,
            OnboardingTask::STATUS_COMPLETED,
            $completedByMembershipId,
            $note,
        );
    }

    /**
     * "Waived required task requires permission/audit" (HR-003 §16) —
     * penegakan permission-nya di HTTP layer (Step 4); service ini
     * tetap mewajibkan actor & catatan alasan tersimpan lewat parameter
     * yang sama seperti completeTask().
     */
    public function waiveTask(
        string $tenantId,
        string $taskId,
        string $completedByMembershipId,
        ?string $note = null,
    ): OnboardingTask {
        return $this->finalizeTask(
            $tenantId,
            $taskId,
            OnboardingTask::STATUS_WAIVED,
            $completedByMembershipId,
            $note,
        );
    }

    /**
     * "CANCELLED is allowed before completion with explicit authorized
     * reason" — $reason WAJIB diisi (bukan opsional). Tidak ada kolom
     * penyimpanan reason di onboarding_cases (§7.12), jadi pemanggil
     * (controller, Step 4) bertanggung jawab menulis $reason ke Core
     * Audit Trail sebagai bukti "explicit authorized reason".
     */
    public function cancel(
        string $tenantId,
        string $caseId,
        string $reason,
    ): OnboardingCase {
        if (trim($reason) === '') {
            throw new OnboardingLifecycleException(
                'Cancelling an Onboarding Case requires an explicit reason.',
            );
        }

        return DB::transaction(function () use ($tenantId, $caseId): OnboardingCase {
            $case = $this->lockCaseForTenant($caseId, $tenantId);

            if (
                in_array(
                    $case->status,
                    [OnboardingCase::STATUS_COMPLETED, OnboardingCase::STATUS_CANCELLED],
                    true,
                )
            ) {
                throw new OnboardingLifecycleException(
                    sprintf(
                        'Onboarding Case [%s] cannot be cancelled from status [%s].',
                        $case->id,
                        $case->status,
                    ),
                );
            }

            $case->status = OnboardingCase::STATUS_CANCELLED;
            $case->save();

            return $case->refresh();
        });
    }

    private function finalizeTask(
        string $tenantId,
        string $taskId,
        string $newStatus,
        string $completedByMembershipId,
        ?string $note,
    ): OnboardingTask {
        return DB::transaction(function () use (
            $tenantId,
            $taskId,
            $newStatus,
            $completedByMembershipId,
            $note,
        ): OnboardingTask {
            $task = $this->lockTaskForTenant($taskId, $tenantId);

            if ($task->status !== OnboardingTask::STATUS_PENDING) {
                throw new OnboardingLifecycleException(
                    sprintf(
                        'Onboarding Task [%s] cannot be finalized from status [%s].',
                        $task->id,
                        $task->status,
                    ),
                );
            }

            $task->status = $newStatus;
            $task->completed_by_membership_id = $completedByMembershipId;
            $task->completed_at = now();
            $task->completion_note = $note;
            $task->save();

            $this->maybeAdvanceToReadyForActivation($task->onboarding_case_id, $tenantId);

            return $task->refresh();
        });
    }

    /**
     * §8.3: "all required tasks complete/waived" -> READY_FOR_ACTIVATION,
     * OTOMATIS — bukan aksi manual terpisah. Dipanggil setiap kali satu
     * tugas selesai/diwaive, dalam transaksi yang sama.
     */
    private function maybeAdvanceToReadyForActivation(
        string $caseId,
        string $tenantId,
    ): void {
        $case = $this->lockCaseForTenant($caseId, $tenantId);

        if ($case->status !== OnboardingCase::STATUS_IN_PROGRESS) {
            return;
        }

        $hasPendingRequiredTasks = OnboardingTask::query()
            ->withoutGlobalScope('tenant')
            ->where('onboarding_case_id', $caseId)
            ->where('tenant_id', $tenantId)
            ->where('is_required', true)
            ->where('status', OnboardingTask::STATUS_PENDING)
            ->exists();

        if (! $hasPendingRequiredTasks) {
            $case->status = OnboardingCase::STATUS_READY_FOR_ACTIVATION;
            $case->save();
        }
    }

    private function lockCaseForTenant(
        string $caseId,
        string $tenantId,
    ): OnboardingCase {
        /** @var OnboardingCase|null $case */
        $case = OnboardingCase::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $caseId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($case === null) {
            throw (new ModelNotFoundException())->setModel(
                OnboardingCase::class,
                [$caseId],
            );
        }

        return $case;
    }

    private function lockTaskForTenant(
        string $taskId,
        string $tenantId,
    ): OnboardingTask {
        /** @var OnboardingTask|null $task */
        $task = OnboardingTask::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($task === null) {
            throw (new ModelNotFoundException())->setModel(
                OnboardingTask::class,
                [$taskId],
            );
        }

        return $task;
    }

    private function requireApplicationExists(
        string $applicationId,
        string $tenantId,
    ): void {
        $exists = RecruitmentApplication::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $applicationId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException())->setModel(
                RecruitmentApplication::class,
                [$applicationId],
            );
        }
    }

    private function requireActiveTemplate(
        string $templateId,
        string $tenantId,
    ): OnboardingTemplate {
        /** @var OnboardingTemplate|null $template */
        $template = OnboardingTemplate::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $templateId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            throw new OnboardingLifecycleException(
                sprintf(
                    'OnboardingTemplate [%s] does not reference an active template in tenant [%s].',
                    $templateId,
                    $tenantId,
                ),
            );
        }

        return $template;
    }
}
