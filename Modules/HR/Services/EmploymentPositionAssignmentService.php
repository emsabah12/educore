<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Models\EmploymentPositionAssignment;
use Modules\HR\Models\Position;
use Modules\HR\Services\Concerns\LocksEmploymentRecords;

/**
 * Implementasi algoritma transaksi "Create Position Assignment" dari
 * HR-002 §9.3.
 */
final readonly class EmploymentPositionAssignmentService
{
    use LocksEmploymentRecords;

    /**
     * @param array{
     *     position_id: string,
     *     employment_placement_id?: string|null,
     *     effective_from: string,
     *     is_primary?: bool,
     * } $data
     */
    public function createAssignment(
        string $tenantId,
        string $employmentId,
        array $data,
    ): EmploymentPositionAssignment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $data,
        ): EmploymentPositionAssignment {
            // Langkah 1: lock Employment.
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            // Langkah 2: Employment wajib ACTIVE.
            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] must be ACTIVE to create a Position Assignment (currently [%s]).',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            // Langkah 3: Position harus ada, aktif, dan milik tenant
            // yang sama.
            $positionId = $data['position_id'];

            $positionIsActive = Position::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $positionId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists();

            if (! $positionIsActive) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Position [%s] does not reference an active catalog entry in tenant [%s].',
                        $positionId,
                        $tenantId,
                    ),
                );
            }

            $employmentPlacementId = $data['employment_placement_id'] ?? null;
            $placementEffectiveFrom = null;

            // Langkah 4: kalau placement diisi, wajib placement TERBUKA
            // milik Employment yang sama persis (bukan milik Employment
            // lain, dan bukan placement yang sudah ditutup).
            if ($employmentPlacementId !== null) {
                /** @var EmploymentPlacement|null $placement */
                $placement = EmploymentPlacement::query()
                    ->withoutGlobalScope('tenant')
                    ->where('id', $employmentPlacementId)
                    ->where('employment_id', $employmentId)
                    ->where('tenant_id', $tenantId)
                    ->whereNull('effective_to')
                    ->first();

                if ($placement === null) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'EmploymentPlacement [%s] is not an open Placement owned by Employment [%s].',
                            $employmentPlacementId,
                            $employmentId,
                        ),
                    );
                }

                $placementEffectiveFrom = $placement->effective_from;
            }

            $effectiveFrom = Carbon::parse($data['effective_from']);

            // Langkah 5: effective_from tidak boleh lebih awal dari
            // start_date Employment, ATAU (kalau di-scope ke Placement)
            // effective_from Placement tersebut.
            if ($effectiveFrom->lt($employment->start_date)) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Position Assignment effective_from [%s] cannot be earlier than Employment start_date [%s].',
                        $effectiveFrom->toDateString(),
                        $employment->start_date->toDateString(),
                    ),
                );
            }

            if ($placementEffectiveFrom !== null && $effectiveFrom->lt($placementEffectiveFrom)) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Position Assignment effective_from [%s] cannot be earlier than the referenced Placement effective_from [%s].',
                        $effectiveFrom->toDateString(),
                        $placementEffectiveFrom->toDateString(),
                    ),
                );
            }

            // Langkah 6: Phase 2A — tidak boleh future-dated.
            if ($effectiveFrom->gt(Carbon::today())) {
                throw new EmploymentLifecycleException(
                    'Position Assignment effective_from cannot be in the future. Scheduled/future assignment is not supported in Phase 2A.',
                );
            }

            // Langkah 7 — cek aplikasi (pre-check) untuk assignment
            // terbuka duplikat. Perhatikan query ini SENGAJA bercabang
            // scoped vs unscoped, mengikuti pola dua partial unique index
            // di migration (lihat Step 8): NULL tidak pernah dianggap
            // "sama" dengan NULL lain, jadi perbandingan
            // employment_placement_id harus eksplisit menangani NULL.
            $duplicateOpenAssignmentQuery = EmploymentPositionAssignment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('employment_id', $employmentId)
                ->where('position_id', $positionId)
                ->whereNull('effective_to');

            $duplicateOpenAssignmentQuery = $employmentPlacementId === null
                ? $duplicateOpenAssignmentQuery->whereNull('employment_placement_id')
                : $duplicateOpenAssignmentQuery->where('employment_placement_id', $employmentPlacementId);

            if ($duplicateOpenAssignmentQuery->exists()) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] already has an open Position Assignment for Position [%s] in this scope.',
                        $employmentId,
                        $positionId,
                    ),
                );
            }

            $isPrimary = $data['is_primary'] ?? false;

            // Langkah 8 (INV-HR-009) — cek aplikasi (pre-check) untuk
            // primary position terbuka ganda.
            if ($isPrimary) {
                $hasOtherOpenPrimary = EmploymentPositionAssignment::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('employment_id', $employmentId)
                    ->where('is_primary', true)
                    ->whereNull('effective_to')
                    ->exists();

                if ($hasOtherOpenPrimary) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'Employment [%s] already has an open primary Position Assignment. Only one open primary Position is allowed at a time (INV-HR-009).',
                            $employmentId,
                        ),
                    );
                }
            }

            // Langkah 9: insert, dengan database partial unique index
            // sebagai jaring pengaman terakhir untuk race condition.
            try {
                return EmploymentPositionAssignment::create([
                    'employment_id' => $employmentId,
                    'position_id' => $positionId,
                    'employment_placement_id' => $employmentPlacementId,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'is_primary' => $isPrimary,
                ]);
            } catch (QueryException $exception) {
                if (
                    str_contains($exception->getMessage(), 'uq_emp_position_assignments_open_scoped')
                    || str_contains($exception->getMessage(), 'uq_emp_position_assignments_open_unscoped')
                ) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'Employment [%s] already has an open Position Assignment for this Position and scope (concurrent creation detected).',
                            $employmentId,
                        ),
                        previous: $exception,
                    );
                }

                if (str_contains($exception->getMessage(), 'uq_emp_position_assignments_open_primary')) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'Employment [%s] already has an open primary Position Assignment (concurrent creation detected, INV-HR-009).',
                            $employmentId,
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }
        });
    }
}
