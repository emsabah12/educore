<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\BenefitParticipationLifecycleException;
use Modules\HR\Models\BenefitProgram;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmployeeBenefitParticipation;
use Modules\HR\Services\Concerns\LocksEmploymentRecords;

/**
 * Implementasi algoritma transaksi Employee Benefit Participation
 * dari HR-006 §7.6 — create (status awal ELIGIBLE) + enroll
 * (ELIGIBLE → ENROLLED, sekaligus tindakan verifikasi
 * administratif). Belum termasuk suspend/reinstate/end/markIneligible
 * — step berikutnya, mengikuti pola inkremental yang sama dengan
 * CompensationAssignmentService.
 *
 * PENTING (§7.6 invariant #4 — "[RESOURCE GAP]"): service ini SAMA
 * SEKALI TIDAK memverifikasi bahwa `beneficiary_person_id` benar-benar
 * dependent/anak sah dari Employee — tidak ada model hubungan
 * keluarga di sistem ini. Yang diverifikasi HANYA `beneficiary_scope`
 * milik BenefitProgram (EMPLOYEE/DEPENDENT/EITHER) dan bahwa Person
 * yang direferensikan benar-benar ada. Verifikasi hubungan keluarga
 * sungguhan tetap manual/di luar sistem sampai resource gap ini
 * diselesaikan secara eksplisit di masa depan.
 *
 * Model ini murni struktur data (lihat EmployeeBenefitParticipation)
 * — kelas ini satu-satunya tempat yang boleh menulis transisi status
 * business-meaningful ke tabel `employee_benefit_participations`.
 */
final readonly class BenefitParticipationService
{
    use LocksEmploymentRecords;

    /**
     * HR-006 §7.6 — Create Employee Benefit Participation.
     *
     * Selalu membuat participation berstatus ELIGIBLE. Transisi ke
     * ENROLLED adalah langkah eksplisit terpisah lewat `enroll()`.
     *
     * @param array{
     *     benefit_program_id: string,
     *     beneficiary_person_id?: string|null,
     *     effective_from: string,
     *     effective_to?: string|null,
     *     notes?: string|null,
     * } $data
     */
    public function create(
        string $tenantId,
        string $employmentId,
        array $data,
    ): EmployeeBenefitParticipation {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $data,
        ): EmployeeBenefitParticipation {
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            // PRD tidak menyatakan ini eksplisit — kami pilih ACTIVE
            // sebagai batasan paling aman, konsisten dengan
            // CompensationAssignmentService.
            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new BenefitParticipationLifecycleException(
                    sprintf(
                        'Employment [%s] must be ACTIVE to create a Benefit Participation (currently [%s]).',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            /** @var BenefitProgram|null $program */
            $program = BenefitProgram::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $data['benefit_program_id'])
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($program === null) {
                throw (new ModelNotFoundException())->setModel(
                    BenefitProgram::class,
                    [$data['benefit_program_id']],
                );
            }

            if (! $program->is_active) {
                throw new BenefitParticipationLifecycleException(
                    sprintf(
                        'BenefitProgram [%s] is not active.',
                        $program->id,
                    ),
                );
            }

            $beneficiaryPersonId = $data['beneficiary_person_id'] ?? null;

            if ($beneficiaryPersonId === null) {
                if ($program->beneficiary_scope === BenefitProgram::BENEFICIARY_SCOPE_DEPENDENT) {
                    throw new BenefitParticipationLifecycleException(
                        sprintf(
                            'BenefitProgram [%s] requires a dependent beneficiary — beneficiary_person_id cannot be omitted.',
                            $program->id,
                        ),
                    );
                }
            } else {
                if ($program->beneficiary_scope === BenefitProgram::BENEFICIARY_SCOPE_EMPLOYEE) {
                    throw new BenefitParticipationLifecycleException(
                        sprintf(
                            'BenefitProgram [%s] only supports the Employee as beneficiary — beneficiary_person_id must be omitted.',
                            $program->id,
                        ),
                    );
                }

                // Hanya memverifikasi Person ini ADA — BUKAN
                // memverifikasi hubungan keluarga sungguhan dengan
                // Employee (lihat catatan [RESOURCE GAP] di docblock
                // kelas).
                $beneficiaryExists = DB::table('persons')
                    ->where('id', $beneficiaryPersonId)
                    ->exists();

                if (! $beneficiaryExists) {
                    throw new BenefitParticipationLifecycleException(
                        sprintf(
                            'beneficiary_person_id [%s] does not reference an existing Person.',
                            $beneficiaryPersonId,
                        ),
                    );
                }
            }

            $effectiveFrom = Carbon::parse($data['effective_from']);
            $effectiveToRaw = $data['effective_to'] ?? null;
            $effectiveTo = $effectiveToRaw !== null
                ? Carbon::parse($effectiveToRaw)
                : null;

            if ($effectiveTo !== null && $effectiveTo->lt($effectiveFrom)) {
                throw new BenefitParticipationLifecycleException(
                    sprintf(
                        'effective_to [%s] cannot be earlier than effective_from [%s].',
                        $effectiveTo->toDateString(),
                        $effectiveFrom->toDateString(),
                    ),
                );
            }

            try {
                return EmployeeBenefitParticipation::create([
                    'employment_id' => $employmentId,
                    'benefit_program_id' => $program->id,
                    'beneficiary_person_id' => $beneficiaryPersonId,
                    'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'effective_to' => $effectiveTo?->toDateString(),
                    'notes' => $data['notes'] ?? null,
                ]);
            } catch (QueryException $exception) {
                if ($this->isDuplicateOpenActiveConflict($exception)) {
                    throw new BenefitParticipationLifecycleException(
                        sprintf(
                            'Employment [%s] already has an open, active participation for BenefitProgram [%s] with the same beneficiary (concurrent creation detected, HR-006 §7.6).',
                            $employmentId,
                            $program->id,
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }
        });
    }

    /**
     * HR-006 §7.6 — Enroll (ELIGIBLE → ENROLLED).
     *
     * Sekaligus BERFUNGSI sebagai tindakan verifikasi administratif —
     * `verified_at`/`verified_by_membership_id` diisi di sini, bukan
     * lewat action terpisah. PRD tidak memisahkan "verified tapi
     * belum enrolled" sebagai state tersendiri, jadi kami tidak
     * menciptakan langkah menengah yang tidak diminta.
     */
    public function enroll(
        string $tenantId,
        string $employmentId,
        string $participationId,
        string $verifierMembershipId,
    ): EmployeeBenefitParticipation {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $participationId,
            $verifierMembershipId,
        ): EmployeeBenefitParticipation {
            $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            /** @var EmployeeBenefitParticipation|null $participation */
            $participation = EmployeeBenefitParticipation::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $participationId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($participation === null) {
                throw (new ModelNotFoundException())->setModel(
                    EmployeeBenefitParticipation::class,
                    [$participationId],
                );
            }

            if ($participation->employment_id !== $employmentId) {
                throw new BenefitParticipationLifecycleException(
                    sprintf(
                        'EmployeeBenefitParticipation [%s] does not belong to Employment [%s].',
                        $participationId,
                        $employmentId,
                    ),
                );
            }

            if ($participation->status !== EmployeeBenefitParticipation::STATUS_ELIGIBLE) {
                throw new BenefitParticipationLifecycleException(
                    sprintf(
                        'EmployeeBenefitParticipation [%s] cannot be enrolled from status [%s]. Only ELIGIBLE participations may transition to ENROLLED.',
                        $participationId,
                        $participation->status,
                    ),
                );
            }

            $this->requireActiveVerifierMembership(
                $verifierMembershipId,
                $tenantId,
            );

            try {
                $participation->status = EmployeeBenefitParticipation::STATUS_ENROLLED;
                $participation->verified_at = now();
                $participation->verified_by_membership_id = $verifierMembershipId;
                $participation->save();
            } catch (QueryException $exception) {
                if ($this->isDuplicateOpenActiveConflict($exception)) {
                    throw new BenefitParticipationLifecycleException(
                        sprintf(
                            'Enrolling EmployeeBenefitParticipation [%s] would conflict with another open, active participation for the same Employment/Program/beneficiary (HR-006 §7.6).',
                            $participationId,
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            return $participation->refresh();
        });
    }

    private function requireActiveVerifierMembership(
        string $membershipId,
        string $tenantId,
    ): void {
        $isActive = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $isActive) {
            throw new BenefitParticipationLifecycleException(
                sprintf(
                    'Verifier Membership [%s] is not ACTIVE in tenant [%s].',
                    $membershipId,
                    $tenantId,
                ),
            );
        }
    }

    private function isDuplicateOpenActiveConflict(QueryException $exception): bool
    {
        return str_contains(
            $exception->getMessage(),
            'uq_benefit_participations_open_active_self',
        )
            || str_contains(
                $exception->getMessage(),
                'uq_benefit_participations_open_active_dependent',
            );
    }
}
