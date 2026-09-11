<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Contracts\MembershipLifecycleServiceInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Person\Contracts\PersonIdentityResolutionServiceInterface;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Models\OnboardingCase;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentHireConversion;
use Modules\HR\Models\RecruitmentHiringDecision;
use Throwable;

/**
 * HR-003 §12 — Hiring Conversion Transaction.
 *
 * Mengorkestrasi 13 langkah §12.2 dalam satu transaksi atomik,
 * menyatukan Recruitment (Application, Candidate, HiringDecision),
 * Onboarding (OnboardingCase), Core (PersonIdentityResolutionService,
 * MembershipLifecycleService), dan RM-HR-01 (EmployeeRepository,
 * EmploymentLifecycleService) — TIDAK membangun primitif baru untuk
 * bagian yang sudah ada, persis semangat WorkspaceEmployeeProvisioningService
 * (HR-017).
 *
 * "If any canonical mutation fails, no partial Person/Membership/
 * Employee/Employment graph may remain from this conversion" (§12.2) —
 * seluruh 13 langkah dibungkus SATU DB::transaction().
 */
final readonly class HireConversionService
{
    public function __construct(
        private PersonIdentityResolutionServiceInterface $identityResolutionService,
        private MembershipLifecycleServiceInterface $membershipLifecycleService,
        private RecruitmentCandidateIdentifierRepositoryInterface $candidateIdentifierRepository,
        private EmployeeRepositoryInterface $employeeRepository,
        private EmploymentLifecycleService $employmentLifecycleService,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    /**
     * @param  array{employment_type_id: string, start_date: string}  $employmentInput
     *
     * @throws RecruitmentLifecycleException Precondition gagal, resolusi
     *                                       identitas UNRESOLVED/CONFLICT
     *                                       tanpa konfirmasi eksplisit,
     *                                       atau Employee butuh
     *                                       recovery manual (§11).
     */
    public function convert(
        string $tenantId,
        string $applicationId,
        array $employmentInput,
        string $actorMembershipId,
        bool $confirmCreateNewPerson = false,
    ): RecruitmentHireConversion {
        return DB::transaction(function () use (
            $tenantId,
            $applicationId,
            $employmentInput,
            $actorMembershipId,
            $confirmCreateNewPerson,
        ): RecruitmentHireConversion {
            // Langkah 1: Lock Application (status divalidasi SETELAH
            // pengecekan idempotensi di bawah — pada panggilan ulang,
            // Application SAH berstatus HIRED, bukan HIRING_APPROVED
            // lagi, dan itu BUKAN error).
            $application = $this->lockApplicationForTenant($applicationId, $tenantId);

            /** @var RecruitmentCandidate $candidate */
            $candidate = RecruitmentCandidate::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $application->candidate_id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            // Langkah 2-3: Lock/create HireConversion row (idempotency).
            $conversion = $this->lockOrCreateConversion($applicationId, $tenantId);

            // Langkah 3 (lanjutan): repeat request -> hasil yang SAMA.
            // WAJIB dicek SEBELUM validasi status Application di bawah,
            // karena pada panggilan ulang Application memang sudah
            // HIRED (bukan HIRING_APPROVED) — itu hasil YANG BENAR dari
            // panggilan pertama, bukan indikasi kesalahan.
            if ($conversion->conversion_status === RecruitmentHireConversion::CONVERSION_SUCCEEDED) {
                return $conversion;
            }

            // Precondisi §12.1 — HANYA ditegakkan untuk percobaan
            // konversi yang BELUM pernah berhasil.
            if ($application->status !== RecruitmentApplication::STATUS_HIRING_APPROVED) {
                throw new RecruitmentLifecycleException(
                    sprintf(
                        'Application [%s] must be HIRING_APPROVED to convert (currently [%s]).',
                        $application->id,
                        $application->status,
                    ),
                );
            }

            $this->requireLatestApprovedHiringDecision($application->id, $tenantId);

            // Langkah 4: Resolve Candidate identity.
            $personId = $this->resolveCandidateIdentity(
                $tenantId,
                $candidate,
                $conversion,
                $actorMembershipId,
                $confirmCreateNewPerson,
            );

            // Langkah 5: Ensure/reuse ACTIVE Membership (Core).
            $membership = $this->membershipLifecycleService->ensureActiveForPersonAndTenant(
                $personId,
                $tenantId,
            );

            // Langkah 6: Resolve Employee.
            $employeeId = $this->resolveEmployee($tenantId, $membership->id, $application);

            // Langkah 7: Assert Employee tidak punya Employment ACTIVE.
            $this->assertNoActiveEmployment($tenantId, $employeeId);

            // Langkah 8: Create Employment PLANNED.
            $employment = $this->employmentLifecycleService->createPlanned(
                tenantId: $tenantId,
                employeeId: $employeeId,
                data: [
                    'employment_type_id' => $employmentInput['employment_type_id'],
                    'start_date' => $employmentInput['start_date'],
                ],
            );

            // Langkah 9: Link Onboarding Case (kalau ada) ke Employee+Employment.
            OnboardingCase::query()
                ->withoutGlobalScope('tenant')
                ->where('application_id', $applicationId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'employee_id' => $employeeId,
                    'employment_id' => $employment->id,
                ]);

            // Langkah 10: Link Candidate ke Person yang sudah resolve.
            if ($candidate->person_id === null) {
                $candidate->person_id = $personId;
                $candidate->save();
            }

            // Langkah 11: Mark Application HIRED.
            $application->status = RecruitmentApplication::STATUS_HIRED;
            $application->finalized_at = now();
            $application->save();

            // Langkah 12: Mark HireConversion SUCCEEDED + result IDs.
            $conversion->conversion_status = RecruitmentHireConversion::CONVERSION_SUCCEEDED;
            $conversion->person_id = $personId;
            $conversion->membership_id = $membership->id;
            $conversion->employee_id = $employeeId;
            $conversion->employment_id = $employment->id;
            $conversion->converted_by_membership_id = $actorMembershipId;
            $conversion->converted_at = now();
            $conversion->save();

            // Langkah 13: Audit TANPA raw sensitive identifier.
            $this->auditSafely($tenantId, $conversion, $actorMembershipId);

            return $conversion->refresh();
        });
    }

    /**
     * Langkah 4: resolusi identitas Candidate -> Person canonical.
     * "unresolved/conflict -> stop before workforce mutation" (§12.2
     * poin 4d) — exception dilempar SEBELUM Membership/Employee/
     * Employment manapun tersentuh.
     */
    private function resolveCandidateIdentity(
        string $tenantId,
        RecruitmentCandidate $candidate,
        RecruitmentHireConversion $conversion,
        string $actorMembershipId,
        bool $confirmCreateNewPerson,
    ): string {
        // 4a: candidate.person_id sudah pernah diisi (mis. dari
        // konversi lampau yang gagal parsial lalu diperbaiki manual).
        if ($candidate->person_id !== null) {
            $conversion->resolution_status = RecruitmentHireConversion::RESOLUTION_MATCHED_EXISTING;
            $conversion->resolved_by_membership_id = $actorMembershipId;
            $conversion->save();

            return $candidate->person_id;
        }

        $strongClaims = $this->candidateIdentifierRepository->listForCandidateWithDecryptedValue(
            $tenantId,
            $candidate->id,
        );

        if ($strongClaims === []) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Candidate [%s] has no strong identifier on record; hiring conversion requires at least one (e.g. National ID).',
                    $candidate->id,
                ),
            );
        }

        $resolution = $this->identityResolutionService->resolveByStrongIdentifiers($strongClaims);

        if ($resolution['status'] === PersonIdentityResolutionServiceInterface::STATUS_CONFLICT) {
            $conversion->resolution_status = RecruitmentHireConversion::RESOLUTION_CONFLICT;
            $conversion->save();

            throw new RecruitmentLifecycleException(
                sprintf(
                    'Candidate [%s] identity claims resolve to multiple different Persons; manual review required before conversion.',
                    $candidate->id,
                ),
            );
        }

        // 4b: exact strong identifier match -> reuse Person.
        if ($resolution['status'] === PersonIdentityResolutionServiceInterface::STATUS_MATCHED_EXISTING) {
            $conversion->resolution_status = RecruitmentHireConversion::RESOLUTION_MATCHED_EXISTING;
            $conversion->resolved_by_membership_id = $actorMembershipId;
            $conversion->save();

            /** @var string $personId */
            $personId = $resolution['person_id'];

            return $personId;
        }

        // UNRESOLVED — tidak ada Person yang cocok. 4c: perlu konfirmasi
        // eksplisit "buat baru", bukan otomatis (HR-013-BR-001 semangat
        // yang sama: keputusan berdampak besar butuh persetujuan
        // eksplisit).
        if (! $confirmCreateNewPerson) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Candidate [%s] identity is UNRESOLVED; explicit confirmation is required to create a new Person.',
                    $candidate->id,
                ),
            );
        }

        $firstClaim = $strongClaims[0];

        $personId = $this->identityResolutionService->createPersonWithIdentifier(
            name: $candidate->display_name,
            type: $firstClaim['type'],
            issuingCountryCode: $firstClaim['issuing_country_code'],
            rawValue: $firstClaim['value'],
        );

        $conversion->resolution_status = RecruitmentHireConversion::RESOLUTION_CREATE_NEW_CONFIRMED;
        $conversion->resolved_by_membership_id = $actorMembershipId;
        $conversion->save();

        return $personId;
    }

    /**
     * Langkah 6 (§11 Employee Resolution):
     *   - Employee normal sudah ada -> reuse;
     *   - tidak ada -> buat profil;
     *   - baris soft-deleted ada -> blokir, minta recovery manual
     *     (unique constraint employees.membership_id akan menangkap ini
     *     kalau pre-check di bawah entah bagaimana terlewat).
     */
    private function resolveEmployee(
        string $tenantId,
        string $membershipId,
        RecruitmentApplication $application,
    ): string {
        $existing = $this->employeeRepository->findByMembershipForTenant($membershipId, $tenantId);

        if ($existing !== null) {
            return (string) $existing['employee_id'];
        }

        $position = $application->vacancy?->position;

        try {
            $created = $this->employeeRepository->createProfileForTenant(
                $tenantId,
                $membershipId,
                [
                    'nip' => null,
                    'jabatan' => $position?->name ?? 'Belum ditentukan',
                ],
            );
        } catch (QueryException $exception) {
            // §11: "soft-deleted Employee row exists -> block automatic
            // conversion and require explicit profile recovery" —
            // employees.membership_id UNIQUE menangkap ini karena baris
            // soft-deleted tetap menempati index unik tersebut.
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Membership [%s] already has an Employee record (possibly soft-deleted); explicit profile recovery is required before conversion.',
                    $membershipId,
                ),
                previous: $exception,
            );
        }

        return (string) $created['employee_id'];
    }

    private function assertNoActiveEmployment(string $tenantId, string $employeeId): void
    {
        $hasActiveEmployment = DB::table('employments')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->exists();

        if ($hasActiveEmployment) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Employee [%s] already has an ACTIVE Employment; hiring conversion cannot proceed.',
                    $employeeId,
                ),
            );
        }
    }

    private function requireLatestApprovedHiringDecision(
        string $applicationId,
        string $tenantId,
    ): void {
        $latestDecision = RecruitmentHiringDecision::query()
            ->withoutGlobalScope('tenant')
            ->where('application_id', $applicationId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('decided_at')
            ->first();

        if (
            $latestDecision === null
            || $latestDecision->decision !== RecruitmentHiringDecision::DECISION_APPROVED
        ) {
            throw new RecruitmentLifecycleException(
                sprintf(
                    'Application [%s] does not have a valid latest APPROVED hiring decision.',
                    $applicationId,
                ),
            );
        }
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
            throw (new ModelNotFoundException)->setModel(
                RecruitmentApplication::class,
                [$applicationId],
            );
        }

        return $application;
    }

    private function lockOrCreateConversion(
        string $applicationId,
        string $tenantId,
    ): RecruitmentHireConversion {
        /** @var RecruitmentHireConversion|null $conversion */
        $conversion = RecruitmentHireConversion::query()
            ->withoutGlobalScope('tenant')
            ->where('application_id', $applicationId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($conversion !== null) {
            return $conversion;
        }

        try {
            return RecruitmentHireConversion::create([
                'application_id' => $applicationId,
            ]);
        } catch (QueryException $exception) {
            // Race condition: baris dibuat request lain persis di
            // antara SELECT dan INSERT — ambil ulang dengan lock.
            /** @var RecruitmentHireConversion $conversion */
            $conversion = RecruitmentHireConversion::query()
                ->withoutGlobalScope('tenant')
                ->where('application_id', $applicationId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            return $conversion;
        }
    }

    private function auditSafely(
        string $tenantId,
        RecruitmentHireConversion $conversion,
        string $actorMembershipId,
    ): void {
        try {
            $this->auditTrail->log(
                eventType: 'hr.recruitment.hire_conversion.succeeded',
                description: 'Hiring conversion succeeded.',
                tenantId: $tenantId,
                actorUserId: null,
                metadata: [
                    // TANPA raw sensitive identifier (§12.2 langkah 13)
                    // — hanya ID hasil, bukan NIK/paspor mentah.
                    'application_id' => $conversion->application_id,
                    'employee_id' => $conversion->employee_id,
                    'employment_id' => $conversion->employment_id,
                    'resolution_status' => $conversion->resolution_status,
                    'actor_membership_id' => $actorMembershipId,
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }
    }
}
