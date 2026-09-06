<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Models\LeaveApprovalPolicy;
use Modules\HR\Models\LeaveApprovalPolicyStep;

/**
 * HR-004 §7.5-7.6 / §10 — Approval Policy Resolution.
 *
 * "Once referenced by a submitted request, a policy version and its
 * steps are immutable. Changes create the next version." Penegakan
 * "sudah pernah dirujuk Application submitted" akan ditambahkan begitu
 * `leave_requests` ada (Fase D) — untuk sekarang, setiap versi baru
 * memang selalu baris baru (append), jadi tidak ada mekanisme mutasi
 * versi lama yang perlu diblokir.
 */
final readonly class LeaveApprovalPolicyService
{
    /**
     * `version_no` OTOMATIS dihitung (max + 1 per policy_code) — "
     * Monotonic within policy code" bukan tanggung jawab pemanggil
     * untuk melacak nomor versi saat ini secara manual.
     *
     * @param array{
     *     policy_code: string,
     *     name: string,
     *     leave_type_id?: string|null,
     *     organization_id?: string|null,
     *     organization_unit_id?: string|null,
     *     employment_type_id?: string|null,
     *     employment_classification_id?: string|null,
     *     decision_mode: string,
     *     effective_from: string,
     *     effective_to?: string|null,
     *     priority?: int,
     * } $data
     */
    public function createPolicyVersion(string $tenantId, array $data): LeaveApprovalPolicy
    {
        if (
            ($data['organization_unit_id'] ?? null) !== null
            && ($data['organization_id'] ?? null) === null
        ) {
            throw new LeaveLifecycleException(
                'organization_unit_id requires organization_id to also be set.',
            );
        }

        $nextVersionNo = (int) LeaveApprovalPolicy::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('policy_code', $data['policy_code'])
            ->max('version_no') + 1;

        $data['version_no'] = $nextVersionNo;

        return LeaveApprovalPolicy::create($data);
    }

    public function addStep(
        string $tenantId,
        string $approvalPolicyId,
        int $stepOrder,
        string $requiredPermission,
        string $scopeStrategy,
        bool $independentApprover = true,
    ): LeaveApprovalPolicyStep {
        $policy = LeaveApprovalPolicy::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $approvalPolicyId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($policy === null) {
            throw (new ModelNotFoundException())->setModel(
                LeaveApprovalPolicy::class,
                [$approvalPolicyId],
            );
        }

        return LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $approvalPolicyId,
            'step_order' => $stepOrder,
            'required_permission' => $requiredPermission,
            'scope_strategy' => $scopeStrategy,
            'independent_approver' => $independentApprover,
        ]);
    }

    /**
     * §10 langkah 6-10: cari kebijakan efektif yang cocok Leave Type
     * (atau generic fallback NULL), Employment Type/Classification,
     * Organization/Unit, dan tanggal submission, lalu rank berdasarkan
     * spesifisitas scope dan employment-filter, dan priority.
     *
     * @throws LeaveLifecycleException LEAVE_APPROVAL_POLICY_NOT_FOUND
     *                                   atau LEAVE_APPROVAL_POLICY_AMBIGUOUS.
     */
    public function resolve(
        string $tenantId,
        string $leaveTypeId,
        string $employmentId,
        string $submissionDate,
    ): LeaveApprovalPolicy {
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

        // §10 langkah 5: organization/unit dari Employment Placement
        // aktif (open + primary).
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

        $submissionDateOnly = Carbon::parse($submissionDate)->toDateString();

        /** @var \Illuminate\Support\Collection<int, LeaveApprovalPolicy> $candidates */
        $candidates = LeaveApprovalPolicy::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $submissionDateOnly)
            ->where(
                fn($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $submissionDateOnly),
            )
            // §10 langkah 6: Leave Type spesifik ATAU generic fallback (NULL).
            ->where(
                fn($query) => $query
                    ->whereNull('leave_type_id')
                    ->orWhere('leave_type_id', $leaveTypeId),
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
                    'LEAVE_APPROVAL_POLICY_NOT_FOUND: no applicable LeaveApprovalPolicy for LeaveType [%s], Employment [%s], date [%s].',
                    $leaveTypeId,
                    $employmentId,
                    $submissionDateOnly,
                ),
            );
        }

        // §10 langkah 7-8: rank berdasarkan (scope specificity,
        // employment-filter specificity, priority) — persis dua dimensi
        // yang disebut eksplisit dokumen, ditambah priority sebagai
        // pemutus akhir.
        $ranked = $candidates
            ->sortByDesc(fn(LeaveApprovalPolicy $policy): array => [
                $policy->scopeSpecificity(),
                $policy->employmentFilterSpecificity(),
                $policy->priority,
            ])
            ->values();

        $winner = $ranked->first();
        $runnerUp = $ranked->get(1);

        if (
            $runnerUp !== null
            && $winner->scopeSpecificity() === $runnerUp->scopeSpecificity()
            && $winner->employmentFilterSpecificity() === $runnerUp->employmentFilterSpecificity()
            && $winner->priority === $runnerUp->priority
        ) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_APPROVAL_POLICY_AMBIGUOUS: policies [%s] and [%s] tie in specificity and priority for LeaveType [%s], Employment [%s].',
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
