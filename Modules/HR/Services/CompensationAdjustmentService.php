<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\CompensationAdjustmentLifecycleException;
use Modules\HR\Models\CompensationAdjustment;
use Modules\HR\Models\CompensationComponent;
use Modules\HR\Models\Employment;
use Modules\HR\Services\Concerns\LocksEmploymentRecords;

/**
 * Implementasi algoritma transaksi Compensation Adjustment dari
 * HR-006 §7.8 — create (DRAFT) → submit (SUBMITTED) → approve/reject
 * (APPROVED/REJECTED), plus cancel (dari DRAFT/SUBMITTED saja —
 * lihat catatan maker-checker & asumsi CANCELLED di docblock
 * `approve()`).
 *
 * Maker-checker (§7.8: "approved_by_membership_id !=
 * requested_by_membership_id for normal approved adjustments")
 * ditegakkan DUA LAPIS: CHECK constraint DB (migration Langkah 5.8,
 * penjaga akhir yang tidak bisa dilewati) DAN pengecekan eksplisit di
 * `approve()` di sini (defense-in-depth, supaya pesan errornya rapi
 * alih-alih QueryException mentah).
 */
final readonly class CompensationAdjustmentService
{
    use LocksEmploymentRecords;

    /**
     * HR-006 §7.8 — Create DRAFT Compensation Adjustment.
     *
     * `requesterMembershipId` SELALU diambil dari konteks operator
     * yang sedang login (bukan input client) — sama seperti pola
     * approver di `CompensationAssignmentService::approve()` — supaya
     * "maker" dalam maker-checker tidak bisa dipalsukan oleh client.
     *
     * @param array{
     *     compensation_component_id?: string|null,
     *     adjustment_type: string,
     *     amount: string,
     *     currency_code: string,
     *     target_period_start: string,
     *     target_period_end: string,
     *     reason: string,
     *     idempotency_key: string,
     * } $data
     */
    public function create(
        string $tenantId,
        string $employmentId,
        string $requesterMembershipId,
        array $data,
    ): CompensationAdjustment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $requesterMembershipId,
            $data,
        ): CompensationAdjustment {
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            // PRD tidak menyatakan ini eksplisit — kami pilih ACTIVE
            // sebagai batasan paling aman, konsisten dengan
            // CompensationAssignmentService/BenefitParticipationService.
            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new CompensationAdjustmentLifecycleException(
                    sprintf(
                        'Employment [%s] must be ACTIVE to create a Compensation Adjustment (currently [%s]).',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            $componentId = $data['compensation_component_id'] ?? null;

            if ($componentId !== null) {
                $componentExists = CompensationComponent::query()
                    ->withoutGlobalScope('tenant')
                    ->where('id', $componentId)
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if (! $componentExists) {
                    throw (new ModelNotFoundException)->setModel(
                        CompensationComponent::class,
                        [$componentId],
                    );
                }
            }

            $targetPeriodStart = Carbon::parse($data['target_period_start']);
            $targetPeriodEnd = Carbon::parse($data['target_period_end']);

            if ($targetPeriodEnd->lt($targetPeriodStart)) {
                throw new CompensationAdjustmentLifecycleException(
                    sprintf(
                        'target_period_end [%s] cannot be earlier than target_period_start [%s].',
                        $targetPeriodEnd->toDateString(),
                        $targetPeriodStart->toDateString(),
                    ),
                );
            }

            try {
                return CompensationAdjustment::create([
                    'employment_id' => $employmentId,
                    'compensation_component_id' => $componentId,
                    'adjustment_type' => $data['adjustment_type'],
                    'amount' => $data['amount'],
                    'currency_code' => strtoupper(trim($data['currency_code'])),
                    'target_period_start' => $targetPeriodStart->toDateString(),
                    'target_period_end' => $targetPeriodEnd->toDateString(),
                    'status' => CompensationAdjustment::STATUS_DRAFT,
                    'reason' => $data['reason'],
                    'requested_by_membership_id' => $requesterMembershipId,
                    'idempotency_key' => $data['idempotency_key'],
                ]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'uq_compensation_adjustments_tenant_idempotency')) {
                    throw new CompensationAdjustmentLifecycleException(
                        sprintf(
                            'A Compensation Adjustment with idempotency_key [%s] already exists for this tenant.',
                            $data['idempotency_key'],
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }
        });
    }

    /**
     * HR-006 §7.8 — Submit (DRAFT → SUBMITTED). Tidak mengubah
     * field approval sama sekali — murni transisi status yang
     * menandai adjustment siap direview approver.
     */
    public function submit(
        string $tenantId,
        string $employmentId,
        string $adjustmentId,
    ): CompensationAdjustment {
        return $this->transitionStatus(
            $tenantId,
            $employmentId,
            $adjustmentId,
            requiredStatus: CompensationAdjustment::STATUS_DRAFT,
            newStatus: CompensationAdjustment::STATUS_SUBMITTED,
        );
    }

    /**
     * HR-006 §7.8 — Approve (SUBMITTED → APPROVED). Maker-checker
     * ditegakkan di sini SEBELUM mencoba insert (bukan hanya
     * mengandalkan CHECK constraint DB) supaya pesan error jelas
     * ("cannot approve your own request") ketimbang QueryException
     * generik.
     */
    public function approve(
        string $tenantId,
        string $employmentId,
        string $adjustmentId,
        string $approverMembershipId,
    ): CompensationAdjustment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $adjustmentId,
            $approverMembershipId,
        ): CompensationAdjustment {
            $adjustment = $this->lockAdjustmentForTenant(
                $tenantId,
                $employmentId,
                $adjustmentId,
            );

            if ($adjustment->status !== CompensationAdjustment::STATUS_SUBMITTED) {
                throw new CompensationAdjustmentLifecycleException(
                    sprintf(
                        'CompensationAdjustment [%s] cannot be approved from status [%s]. Only SUBMITTED adjustments may be approved.',
                        $adjustmentId,
                        $adjustment->status,
                    ),
                );
            }

            if ($approverMembershipId === $adjustment->requested_by_membership_id) {
                throw new CompensationAdjustmentLifecycleException(
                    sprintf(
                        'CompensationAdjustment [%s] cannot be approved by the same Membership [%s] that requested it (maker-checker, HR-006 §7.8).',
                        $adjustmentId,
                        $approverMembershipId,
                    ),
                );
            }

            $this->requireActiveMembership($approverMembershipId, $tenantId);

            $adjustment->status = CompensationAdjustment::STATUS_APPROVED;
            $adjustment->approved_by_membership_id = $approverMembershipId;
            $adjustment->approved_at = now();
            $adjustment->save();

            return $adjustment->refresh();
        });
    }

    /**
     * HR-006 §7.8 — Reject (SUBMITTED → REJECTED). Field approval
     * TETAP kosong (CHECK constraint DB mewajibkan ini untuk semua
     * status selain APPROVED) — PRD tidak menyediakan kolom
     * rejected_by/rejected_at terpisah, jadi siapa yang menolak TIDAK
     * tercatat di baris ini sendiri (keterbatasan skema yang
     * diwariskan, bukan sesuatu yang saya perbaiki sendiri di luar
     * PRD).
     */
    public function reject(
        string $tenantId,
        string $employmentId,
        string $adjustmentId,
        string $reviewerMembershipId,
    ): CompensationAdjustment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $adjustmentId,
            $reviewerMembershipId,
        ): CompensationAdjustment {
            $adjustment = $this->lockAdjustmentForTenant(
                $tenantId,
                $employmentId,
                $adjustmentId,
            );

            if ($adjustment->status !== CompensationAdjustment::STATUS_SUBMITTED) {
                throw new CompensationAdjustmentLifecycleException(
                    sprintf(
                        'CompensationAdjustment [%s] cannot be rejected from status [%s]. Only SUBMITTED adjustments may be rejected.',
                        $adjustmentId,
                        $adjustment->status,
                    ),
                );
            }

            $this->requireActiveMembership($reviewerMembershipId, $tenantId);

            $adjustment->status = CompensationAdjustment::STATUS_REJECTED;
            $adjustment->save();

            return $adjustment->refresh();
        });
    }

    /**
     * HR-006 §7.8 — Cancel (DRAFT/SUBMITTED → CANCELLED). SENGAJA
     * hanya dari status pra-approval — lihat asumsi eksplisit di
     * migration Langkah 5.8: "CANCELLED hanya bisa terjadi SEBELUM
     * approval". PRD tidak menyebutkan skenario "APPROVED lalu
     * dibatalkan" untuk tabel ini.
     */
    public function cancel(
        string $tenantId,
        string $employmentId,
        string $adjustmentId,
    ): CompensationAdjustment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $adjustmentId,
        ): CompensationAdjustment {
            $adjustment = $this->lockAdjustmentForTenant(
                $tenantId,
                $employmentId,
                $adjustmentId,
            );

            if (! in_array($adjustment->status, [
                CompensationAdjustment::STATUS_DRAFT,
                CompensationAdjustment::STATUS_SUBMITTED,
            ], true)) {
                throw new CompensationAdjustmentLifecycleException(
                    sprintf(
                        'CompensationAdjustment [%s] cannot be cancelled from status [%s]. Only DRAFT or SUBMITTED adjustments may be cancelled.',
                        $adjustmentId,
                        $adjustment->status,
                    ),
                );
            }

            $adjustment->status = CompensationAdjustment::STATUS_CANCELLED;
            $adjustment->save();

            return $adjustment->refresh();
        });
    }

    private function transitionStatus(
        string $tenantId,
        string $employmentId,
        string $adjustmentId,
        string $requiredStatus,
        string $newStatus,
    ): CompensationAdjustment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $adjustmentId,
            $requiredStatus,
            $newStatus,
        ): CompensationAdjustment {
            $adjustment = $this->lockAdjustmentForTenant(
                $tenantId,
                $employmentId,
                $adjustmentId,
            );

            if ($adjustment->status !== $requiredStatus) {
                throw new CompensationAdjustmentLifecycleException(
                    sprintf(
                        'CompensationAdjustment [%s] cannot transition to [%s] from status [%s]. Required prior status: [%s].',
                        $adjustmentId,
                        $newStatus,
                        $adjustment->status,
                        $requiredStatus,
                    ),
                );
            }

            $adjustment->status = $newStatus;
            $adjustment->save();

            return $adjustment->refresh();
        });
    }

    private function lockAdjustmentForTenant(
        string $tenantId,
        string $employmentId,
        string $adjustmentId,
    ): CompensationAdjustment {
        // Lock Employment juga — murni tenant-safety (memastikan
        // Employment memang ada di tenant ini), bukan syarat status
        // tertentu (beda dari create(), yang mewajibkan ACTIVE).
        $this->lockEmploymentForTenant($employmentId, $tenantId);

        /** @var CompensationAdjustment|null $adjustment */
        $adjustment = CompensationAdjustment::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $adjustmentId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($adjustment === null) {
            throw (new ModelNotFoundException)->setModel(
                CompensationAdjustment::class,
                [$adjustmentId],
            );
        }

        if ($adjustment->employment_id !== $employmentId) {
            throw new CompensationAdjustmentLifecycleException(
                sprintf(
                    'CompensationAdjustment [%s] does not belong to Employment [%s].',
                    $adjustmentId,
                    $employmentId,
                ),
            );
        }

        return $adjustment;
    }

    private function requireActiveMembership(
        string $membershipId,
        string $tenantId,
    ): void {
        $isActive = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $isActive) {
            throw new CompensationAdjustmentLifecycleException(
                sprintf(
                    'Membership [%s] is not ACTIVE in tenant [%s].',
                    $membershipId,
                    $tenantId,
                ),
            );
        }
    }
}
