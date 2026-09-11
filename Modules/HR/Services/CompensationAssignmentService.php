<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\CompensationLifecycleException;
use Modules\HR\Models\CompensationAssignment;
use Modules\HR\Models\CompensationComponent;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPositionAssignment;
use Modules\HR\Services\Concerns\LocksEmploymentRecords;

/**
 * Implementasi algoritma transaksi Compensation Assignment dari
 * HR-006 §7.3 — createDraft (belum menyentuh transisi
 * approve/end/correct, itu step berikutnya karena masing-masing
 * punya invariant sendiri yang lebih baik ditulis terpisah daripada
 * satu method raksasa).
 *
 * Model ini murni struktur data (lihat CompensationAssignment) —
 * kelas ini satu-satunya tempat yang boleh menulis transisi status
 * business-meaningful ke tabel `compensation_assignments`.
 */
final readonly class CompensationAssignmentService
{
    use LocksEmploymentRecords;

    /**
     * HR-006 §7.3 — Create DRAFT Compensation Assignment.
     *
     * Selalu membuat assignment berstatus DRAFT. Transisi ke APPROVED
     * adalah langkah eksplisit terpisah lewat `approve()` — mengikuti
     * pola create/activate yang sama dengan EmploymentLifecycleService.
     *
     * @param array{
     *     compensation_component_id: string,
     *     employment_position_assignment_id?: string|null,
     *     amount?: string|null,
     *     rate?: string|null,
     *     currency_code: string,
     *     effective_from: string,
     *     effective_to?: string|null,
     *     reason?: string|null,
     * } $data
     */
    public function createDraft(
        string $tenantId,
        string $employmentId,
        array $data,
    ): CompensationAssignment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $data,
        ): CompensationAssignment {
            // Langkah 1: lock Employment.
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            // Langkah 2: Employment wajib ACTIVE. PRD tidak
            // menyatakan ini eksplisit untuk DRAFT (hanya "effective
            // range harus di dalam siklus hidup Employment") — kami
            // pilih ACTIVE sebagai batasan paling aman untuk rilis
            // pertama ini, konsisten dengan EmploymentPlacementService.
            // Bisa dilonggarkan di step berikutnya kalau ada
            // kebutuhan bisnis nyata untuk menyiapkan draft di muka
            // untuk Employment yang masih PLANNED.
            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new CompensationLifecycleException(
                    sprintf(
                        'Employment [%s] must be ACTIVE to create a Compensation Assignment (currently [%s]).',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            // Langkah 3: resolve + lock CompensationComponent,
            // wajib aktif.
            /** @var CompensationComponent|null $component */
            $component = CompensationComponent::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $data['compensation_component_id'])
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($component === null) {
                throw (new ModelNotFoundException())->setModel(
                    CompensationComponent::class,
                    [$data['compensation_component_id']],
                );
            }

            if (! $component->is_active) {
                throw new CompensationLifecycleException(
                    sprintf(
                        'CompensationComponent [%s] is not active.',
                        $component->id,
                    ),
                );
            }

            // Langkah 4: value_mode component menentukan field mana
            // yang wajib diisi — CHECK constraint DB cuma menjamin
            // "tepat satu terisi & positif", pemetaan tepat ke
            // value_mode adalah tanggung jawab service ini.
            $amount = $data['amount'] ?? null;
            $rate = $data['rate'] ?? null;

            if ($component->value_mode === CompensationComponent::VALUE_MODE_FIXED_AMOUNT) {
                if ($amount === null || $rate !== null) {
                    throw new CompensationLifecycleException(
                        sprintf(
                            'CompensationComponent [%s] uses FIXED_AMOUNT — amount is required and rate must be omitted.',
                            $component->id,
                        ),
                    );
                }
            } else {
                if ($rate === null || $amount !== null) {
                    throw new CompensationLifecycleException(
                        sprintf(
                            'CompensationComponent [%s] uses RATE_PER_UNIT — rate is required and amount must be omitted.',
                            $component->id,
                        ),
                    );
                }
            }

            // Langkah 5 (application invariant, HR-006 §7.3 — tidak
            // bisa ditegakkan lewat DB FK karena
            // employment_position_assignments belum punya supporting
            // unique key 3-kolom): kalau discope ke satu Position
            // Assignment, Position Assignment itu harus benar-benar
            // milik Employment yang sama.
            $employmentPositionAssignmentId = $data['employment_position_assignment_id'] ?? null;

            if ($employmentPositionAssignmentId !== null) {
                /** @var EmploymentPositionAssignment|null $positionAssignment */
                $positionAssignment = EmploymentPositionAssignment::query()
                    ->withoutGlobalScope('tenant')
                    ->where('id', $employmentPositionAssignmentId)
                    ->where('tenant_id', $tenantId)
                    ->first();

                if ($positionAssignment === null) {
                    throw (new ModelNotFoundException())->setModel(
                        EmploymentPositionAssignment::class,
                        [$employmentPositionAssignmentId],
                    );
                }

                if ($positionAssignment->employment_id !== $employmentId) {
                    throw new CompensationLifecycleException(
                        sprintf(
                            'EmploymentPositionAssignment [%s] does not belong to Employment [%s].',
                            $employmentPositionAssignmentId,
                            $employmentId,
                        ),
                    );
                }
            }

            // Langkah 6: effective_from tidak boleh lebih awal dari
            // Employment start_date (pola sama persis dengan
            // EmploymentPlacementService).
            $effectiveFrom = Carbon::parse($data['effective_from']);

            if ($effectiveFrom->lt($employment->start_date)) {
                throw new CompensationLifecycleException(
                    sprintf(
                        'Compensation effective_from [%s] cannot be earlier than Employment start_date [%s].',
                        $effectiveFrom->toDateString(),
                        $employment->start_date->toDateString(),
                    ),
                );
            }

            $effectiveToRaw = $data['effective_to'] ?? null;
            $effectiveTo = $effectiveToRaw !== null
                ? Carbon::parse($effectiveToRaw)
                : null;

            if ($effectiveTo !== null && $effectiveTo->lt($effectiveFrom)) {
                throw new CompensationLifecycleException(
                    sprintf(
                        'Compensation effective_to [%s] cannot be earlier than effective_from [%s].',
                        $effectiveTo->toDateString(),
                        $effectiveFrom->toDateString(),
                    ),
                );
            }

            return CompensationAssignment::create([
                'employment_id' => $employmentId,
                'compensation_component_id' => $component->id,
                'employment_position_assignment_id' => $employmentPositionAssignmentId,
                'status' => CompensationAssignment::STATUS_DRAFT,
                'amount' => $amount,
                'rate' => $rate,
                'currency_code' => strtoupper(trim($data['currency_code'])),
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => $effectiveTo?->toDateString(),
                'reason' => $data['reason'] ?? null,
            ]);
        });
    }

    /**
     * HR-006 §7.3 — Approve DRAFT Compensation Assignment.
     *
     * Application-level overlap pre-check SENGAJA tidak direplikasi
     * di sini (beda dengan EmploymentPlacementService's duplicate
     * pre-check) — meniru ulang logika overlap tanggal GiST di PHP
     * berisiko "menyimpang" dari kebenaran DB yang sebenarnya. DB
     * exclusion constraint tetap satu-satunya sumber kebenaran
     * (§7.4: "Application validation remains defense-in-depth; DB is
     * final race-condition guard") — di sini kita HANYA punya sisi
     * DB itu, ditangkap dan diterjemahkan jadi pesan yang rapi.
     */
    public function approve(
        string $tenantId,
        string $employmentId,
        string $assignmentId,
        string $approverMembershipId,
    ): CompensationAssignment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $assignmentId,
            $approverMembershipId,
        ): CompensationAssignment {
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new CompensationLifecycleException(
                    sprintf(
                        'Employment [%s] must be ACTIVE to approve a Compensation Assignment (currently [%s]).',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            /** @var CompensationAssignment|null $assignment */
            $assignment = CompensationAssignment::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $assignmentId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw (new ModelNotFoundException())->setModel(
                    CompensationAssignment::class,
                    [$assignmentId],
                );
            }

            if ($assignment->employment_id !== $employmentId) {
                throw new CompensationLifecycleException(
                    sprintf(
                        'CompensationAssignment [%s] does not belong to Employment [%s].',
                        $assignmentId,
                        $employmentId,
                    ),
                );
            }

            if ($assignment->status !== CompensationAssignment::STATUS_DRAFT) {
                throw new CompensationLifecycleException(
                    sprintf(
                        'CompensationAssignment [%s] cannot be approved from status [%s]. Only DRAFT assignments may transition to APPROVED.',
                        $assignmentId,
                        $assignment->status,
                    ),
                );
            }

            $this->requireActiveApproverMembership(
                $approverMembershipId,
                $tenantId,
            );

            try {
                $assignment->status = CompensationAssignment::STATUS_APPROVED;
                $assignment->approved_by_membership_id = $approverMembershipId;
                $assignment->approved_at = now();
                $assignment->save();
            } catch (QueryException $exception) {
                if ($this->isApprovedOverlapConflict($exception)) {
                    throw new CompensationLifecycleException(
                        sprintf(
                            'Approving CompensationAssignment [%s] would overlap with another APPROVED assignment for the same Employment/Component/scope (HR-006 §7.4).',
                            $assignmentId,
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            return $assignment->refresh();
        });
    }

    private function requireActiveApproverMembership(
        string $membershipId,
        string $tenantId,
    ): void {
        $isActive = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $isActive) {
            throw new CompensationLifecycleException(
                sprintf(
                    'Approver Membership [%s] is not ACTIVE in tenant [%s].',
                    $membershipId,
                    $tenantId,
                ),
            );
        }
    }

    private function isApprovedOverlapConflict(QueryException $exception): bool
    {
        return str_contains(
            $exception->getMessage(),
            'excl_compensation_assignments_approved_overlap_unscoped',
        )
            || str_contains(
                $exception->getMessage(),
                'excl_compensation_assignments_approved_overlap_scoped',
            );
    }
}
