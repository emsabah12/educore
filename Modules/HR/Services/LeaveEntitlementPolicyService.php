<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Models\LeaveEntitlementPolicy;
use Modules\HR\Models\LeaveType;

/**
 * HR-004 §7.2 — Resolusi kebijakan entitlement fixed. Method
 * {@see resolve()} adalah implementasi 8 langkah seleksi kebijakan
 * dari data dictionary, TIDAK PERNAH memilih baris pertama secara acak
 * ketika ada tie yang tidak terselesaikan.
 */
final readonly class LeaveEntitlementPolicyService
{
    /**
     * @param array{
     *     leave_type_id: string,
     *     organization_id?: string|null,
     *     organization_unit_id?: string|null,
     *     employment_type_id?: string|null,
     *     employment_classification_id?: string|null,
     *     period_basis: string,
     *     grant_units: float|string,
     *     carryover_mode?: string,
     *     carryover_limit_units?: float|string|null,
     *     effective_from: string,
     *     effective_to?: string|null,
     *     priority?: int,
     * } $data
     */
    public function createPolicy(string $tenantId, array $data): LeaveEntitlementPolicy
    {
        $leaveType = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $data['leave_type_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if ($leaveType === null) {
            throw (new ModelNotFoundException())->setModel(
                LeaveType::class,
                [$data['leave_type_id']],
            );
        }

        if (! $leaveType->isBalanceBacked()) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LeaveType [%s] is not BALANCE-backed; an entitlement policy would never apply.',
                    $leaveType->id,
                ),
            );
        }

        if (
            ($data['organization_unit_id'] ?? null) !== null
            && ($data['organization_id'] ?? null) === null
        ) {
            throw new LeaveLifecycleException(
                'organization_unit_id requires organization_id to also be set.',
            );
        }

        return LeaveEntitlementPolicy::create($data);
    }

    /**
     * 8 langkah resolusi §7.2. $periodStart menentukan efektivitas
     * kebijakan (langkah 1).
     *
     * @throws LeaveLifecycleException Kalau tidak ada kandidat
     *                                   (NOT_FOUND) atau ada tie yang
     *                                   tidak terselesaikan (AMBIGUOUS).
     */
    public function resolve(
        string $tenantId,
        string $leaveTypeId,
        string $employmentId,
        string $periodStart,
    ): LeaveEntitlementPolicy {
        $employment = Employment::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $employmentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($employment === null) {
            throw (new ModelNotFoundException())->setModel(
                Employment::class,
                [$employmentId],
            );
        }

        // §7.2 langkah 3: organization/unit dari Employment Placement
        // aktif (open + primary) — bukan dari Application/request lain.
        $openPrimaryPlacement = EmploymentPlacement::query()
            ->withoutGlobalScope('tenant')
            ->where('employment_id', $employmentId)
            ->where('tenant_id', $tenantId)
            ->whereNull('effective_to')
            ->where('is_primary', true)
            ->with('organizationalAssignment')
            ->first();

        $placementOrganizationId = $openPrimaryPlacement?->organizationalAssignment?->organization_id;
        $placementOrganizationUnitId = $openPrimaryPlacement?->organizationalAssignment?->organization_unit_id;

        $periodStartDate = Carbon::parse($periodStart)->toDateString();

        /** @var \Illuminate\Support\Collection<int, LeaveEntitlementPolicy> $candidates */
        $candidates = LeaveEntitlementPolicy::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $periodStartDate)
            ->where(
                fn($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $periodStartDate),
            )
            ->where(
                fn($query) => $query
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', $placementOrganizationId),
            )
            ->where(
                fn($query) => $query
                    ->whereNull('organization_unit_id')
                    ->orWhere('organization_unit_id', $placementOrganizationUnitId),
            )
            ->where(
                fn($query) => $query
                    ->whereNull('employment_type_id')
                    ->orWhere('employment_type_id', $employment->employment_type_id),
            )
            ->where(
                fn($query) => $query
                    ->whereNull('employment_classification_id')
                    ->orWhere('employment_classification_id', $employment->employment_classification_id),
            )
            ->get();

        if ($candidates->isEmpty()) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_ENTITLEMENT_POLICY_NOT_FOUND: no applicable LeaveEntitlementPolicy for LeaveType [%s], Employment [%s], period [%s].',
                    $leaveTypeId,
                    $employmentId,
                    $periodStartDate,
                ),
            );
        }

        // §7.2 langkah 5-7: rank berdasarkan (scope specificity,
        // employment-filter specificity, priority) — dalam urutan itu.
        $ranked = $candidates
            ->sortByDesc(fn(LeaveEntitlementPolicy $policy): array => [
                $policy->scopeSpecificity(),
                $policy->employmentFilterSpecificity(),
                $policy->priority,
            ])
            ->values();

        $winner = $ranked->first();
        $runnerUp = $ranked->get(1);

        // §7.2 langkah 8: tie spesifisitas + priority yang SAMA PERSIS
        // adalah konflik konfigurasi — GAGAL eksplisit, bukan memilih
        // baris pertama secara acak.
        if (
            $runnerUp !== null
            && $winner->scopeSpecificity() === $runnerUp->scopeSpecificity()
            && $winner->employmentFilterSpecificity() === $runnerUp->employmentFilterSpecificity()
            && $winner->priority === $runnerUp->priority
        ) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_ENTITLEMENT_POLICY_AMBIGUOUS: policies [%s] and [%s] tie in specificity and priority for LeaveType [%s], Employment [%s].',
                    $winner->id,
                    $runnerUp->id,
                    $leaveTypeId,
                    $employmentId,
                ),
            );
        }

        return $winner;
    }
}
