<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Organization\Contracts\OrganizationalAuthorizationServiceInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Models\LeaveApprovalPolicyStep;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveRequestApprovalStep;
use Modules\HR\Models\LeaveRequestEntitlementAllocation;
use Modules\HR\Models\LeaveType;
use Throwable;

/**
 * HR-004 §14.1 (final approval), §14.3 (reject). Ini implementasi
 * paling kompleks di seluruh HR-004 — mengunci entitlement lintas
 * bucket dalam urutan deterministik, menulis CONSUME idempoten, dan
 * merevalidasi otorisasi execution-time (INV-HR-LEAVE-008) — bukan
 * cuma memercayai bahwa approver berwenang saat request disubmit.
 */
final readonly class LeaveApprovalService
{
    public function __construct(
        private AuthorizationServiceInterface $tenantAuthorization,
        private OrganizationalAuthorizationServiceInterface $organizationalAuthorization,
        private OrganizationalContextInterface $organizationalContext,
        private LeaveBalanceService $balanceService,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    /**
     * §14.1 langkah 1-6 (kalau masih ada step lain) atau 1-18 (kalau
     * ini step terakhir — otomatis memanggil finalisasi).
     */
    public function approveCurrentStep(
        string $tenantId,
        string $leaveRequestId,
        string $approverMembershipId,
        ?string $decisionNote = null,
    ): LeaveRequest {
        return DB::transaction(function () use (
            $tenantId,
            $leaveRequestId,
            $approverMembershipId,
            $decisionNote,
        ): LeaveRequest {
            $request = $this->lockRequestForTenant($leaveRequestId, $tenantId);
            $this->requireActionableStatus($request);

            $currentStep = $this->lockCurrentActionableStep($request->id, $tenantId);

            $this->verifyApproverAuthorization($tenantId, $request, $currentStep);
            $this->verifyIndependentApprover($tenantId, $request, $currentStep, $approverMembershipId);

            $currentStep->status = LeaveRequestApprovalStep::STATUS_APPROVED;
            $currentStep->decided_by_membership_id = $approverMembershipId;
            $currentStep->decision_note = $decisionNote;
            $currentStep->decided_at = now();
            $currentStep->save();

            $hasMoreSteps = LeaveRequestApprovalStep::query()
                ->withoutGlobalScope('tenant')
                ->where('leave_request_id', $request->id)
                ->where('tenant_id', $tenantId)
                ->where('status', LeaveRequestApprovalStep::STATUS_PENDING)
                ->exists();

            if ($hasMoreSteps) {
                // §14.1 langkah 6.
                $request->status = LeaveRequest::STATUS_IN_REVIEW;
                $request->save();

                return $request->refresh();
            }

            // §14.1 langkah 7-18.
            return $this->finalize($tenantId, $request, $approverMembershipId);
        });
    }

    /**
     * §14.3.
     */
    public function rejectCurrentStep(
        string $tenantId,
        string $leaveRequestId,
        string $approverMembershipId,
        ?string $decisionNote = null,
    ): LeaveRequest {
        return DB::transaction(function () use (
            $tenantId,
            $leaveRequestId,
            $approverMembershipId,
            $decisionNote,
        ): LeaveRequest {
            $request = $this->lockRequestForTenant($leaveRequestId, $tenantId);
            $this->requireActionableStatus($request);

            $currentStep = $this->lockCurrentActionableStep($request->id, $tenantId);

            $this->verifyApproverAuthorization($tenantId, $request, $currentStep);
            $this->verifyIndependentApprover($tenantId, $request, $currentStep, $approverMembershipId);

            $currentStep->status = LeaveRequestApprovalStep::STATUS_REJECTED;
            $currentStep->decided_by_membership_id = $approverMembershipId;
            $currentStep->decision_note = $decisionNote;
            $currentStep->decided_at = now();
            $currentStep->save();

            // §14.3 langkah 7: langkah-langkah setelahnya -> SKIPPED.
            LeaveRequestApprovalStep::query()
                ->withoutGlobalScope('tenant')
                ->where('leave_request_id', $request->id)
                ->where('tenant_id', $tenantId)
                ->where('status', LeaveRequestApprovalStep::STATUS_PENDING)
                ->update(['status' => LeaveRequestApprovalStep::STATUS_SKIPPED]);

            $request->status = LeaveRequest::STATUS_REJECTED;
            $request->final_decided_at = now();
            $request->save();

            $this->auditSafely(
                $tenantId,
                'hr.leave.request.rejected',
                'Leave Request rejected.',
                $request,
                $approverMembershipId,
            );

            return $request->refresh();
        });
    }

    /**
     * §14.1 langkah 7-18. Method PUBLIC — dipanggil juga langsung untuk
     * `decision_mode=AUTO` (tidak ada step manual sama sekali).
     */
    public function finalizeApproval(
        string $tenantId,
        string $leaveRequestId,
        ?string $actorMembershipId = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($tenantId, $leaveRequestId, $actorMembershipId): LeaveRequest {
            $request = $this->lockRequestForTenant($leaveRequestId, $tenantId);
            $this->requireActionableStatus($request);

            return $this->finalize($tenantId, $request, $actorMembershipId);
        });
    }

    private function finalize(
        string $tenantId,
        LeaveRequest $request,
        ?string $actorMembershipId,
    ): LeaveRequest {
        $leaveType = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $request->leave_type_id)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($leaveType === null) {
            throw (new ModelNotFoundException)->setModel(
                LeaveType::class,
                [$request->leave_type_id],
            );
        }

        if ($leaveType->isBalanceBacked()) {
            $this->finalizeBalanceBacked($tenantId, $request);
        }

        // §14.1 langkah 14-15. CHECK constraint status di DB (unit
        // consistency) sudah menegakkan nilai valid; exclusion
        // constraint GiST menegakkan INV-HR-LEAVE-013.
        $request->status = LeaveRequest::STATUS_APPROVED;
        $request->final_decided_at = now();

        try {
            $request->save();
        } catch (QueryException $exception) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_REQUEST_OVERLAP: Employment [%s] already has an overlapping APPROVED request.',
                    $request->employment_id,
                ),
                previous: $exception,
            );
        }

        // §14.1 langkah 16.
        $this->auditSafely(
            $tenantId,
            'hr.leave.request.approved',
            'Leave Request approved.',
            $request,
            $actorMembershipId,
        );

        return $request->refresh();
    }

    /**
     * §14.1 langkah 7-13 — HANYA untuk LeaveType `BALANCE`.
     *
     * **Batasan cakupan yang diakui secara jujur**: resolusi otomatis
     * di sini hanya menangani request yang MUAT PENUH di dalam SATU
     * entitlement bucket. Pemisahan otomatis lintas periode entitlement
     * (§7.8: "A request crossing entitlement periods can have multiple
     * rows") BELUM diimplementasikan — dokumen sendiri mengakui
     * [RESOURCE GAP] soal kalkulasi kalender kerja (§12.3), jadi saya
     * tidak mengarang logika pemisahan yang belum punya dasar aturan
     * bisnis yang jelas.
     */
    private function finalizeBalanceBacked(string $tenantId, LeaveRequest $request): void
    {
        $existingAllocations = LeaveRequestEntitlementAllocation::query()
            ->withoutGlobalScope('tenant')
            ->where('leave_request_id', $request->id)
            ->where('tenant_id', $tenantId)
            ->get();

        if ($existingAllocations->isEmpty()) {
            $entitlement = LeaveEntitlement::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('employment_id', $request->employment_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('status', LeaveEntitlement::STATUS_ACTIVE)
                ->where('period_start', '<=', $request->starts_at->toDateString())
                ->where('period_end', '>=', $request->ends_at->subDay()->toDateString())
                ->first();

            if ($entitlement === null) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'LEAVE_ENTITLEMENT_NOT_FOUND: no single Entitlement bucket covers Leave Request [%s] period; automatic multi-period allocation is not supported in this phase.',
                        $request->id,
                    ),
                );
            }

            LeaveRequestEntitlementAllocation::create([
                'leave_request_id' => $request->id,
                'entitlement_id' => $entitlement->id,
                'allocated_units' => $request->requested_units,
            ]);
        }

        // §14.1 langkah 9: lock SEMUA entitlement yang direferensikan
        // dalam URUTAN ID DETERMINISTIK — mencegah deadlock lintas
        // bucket kalau ada dua approval berbeda saling mengunci
        // entitlement yang sama dengan urutan berbeda.
        $allocations = LeaveRequestEntitlementAllocation::query()
            ->withoutGlobalScope('tenant')
            ->where('leave_request_id', $request->id)
            ->where('tenant_id', $tenantId)
            ->orderBy('entitlement_id')
            ->get();

        $totalAllocated = (float) $allocations->sum('allocated_units');

        if (abs($totalAllocated - (float) $request->requested_units) > 0.001) {
            throw new LeaveLifecycleException(
                sprintf(
                    'Sum of allocations [%s] does not match requested units [%s] for Leave Request [%s].',
                    $totalAllocated,
                    $request->requested_units,
                    $request->id,
                ),
            );
        }

        LeaveEntitlement::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('id', $allocations->pluck('entitlement_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // §14.1 langkah 13: satu CONSUME idempoten per alokasi.
        foreach ($allocations as $allocation) {
            $this->balanceService->consumeForApprovedRequest(
                $tenantId,
                $allocation->entitlement_id,
                $request->id,
                (string) $allocation->allocated_units,
            );
        }
    }

    private function verifyApproverAuthorization(
        string $tenantId,
        LeaveRequest $request,
        LeaveRequestApprovalStep $step,
    ): void {
        if ($step->scope_strategy === LeaveApprovalPolicyStep::SCOPE_TENANT) {
            if (! $this->tenantAuthorization->hasPermission($step->required_permission)) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'LEAVE_APPROVER_NOT_AUTHORIZED: missing tenant-wide permission [%s].',
                        $step->required_permission,
                    ),
                );
            }

            return;
        }

        // REQUEST_PLACEMENT / ORGANIZATION — butuh Organizational
        // Context ambient yang SUDAH diverifikasi berkorespondensi
        // dengan scope permintaan (§11).
        $ambientContext = $this->organizationalContext->getCurrentContext();

        if ($ambientContext === null) {
            throw new LeaveLifecycleException(
                'LEAVE_SCOPE_MISMATCH: no verified organizational context for scoped approval.',
            );
        }

        $placement = $request->approval_context_placement_id !== null
            ? EmploymentPlacement::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $request->approval_context_placement_id)
                ->where('tenant_id', $tenantId)
                ->with('organizationalAssignment')
                ->first()
            : null;

        $placementOrganizationId = $placement?->organizationalAssignment?->organization_id;
        $placementOrganizationUnitId = $placement?->organizationalAssignment?->organization_unit_id;

        if ($placementOrganizationId === null) {
            throw new LeaveLifecycleException(
                'LEAVE_SCOPE_MISMATCH: Leave Request has no approval context placement to scope against.',
            );
        }

        $scopeMatches = $step->scope_strategy === LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT
            ? $ambientContext->organizationId === $placementOrganizationId
            && $ambientContext->organizationUnitId === $placementOrganizationUnitId
            : $ambientContext->organizationId === $placementOrganizationId;

        if (! $scopeMatches) {
            throw new LeaveLifecycleException(
                'LEAVE_SCOPE_MISMATCH: current organizational context does not match the required approval scope.',
            );
        }

        if (! $this->organizationalAuthorization->hasPermission($step->required_permission)) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_APPROVER_NOT_AUTHORIZED: missing permission [%s] in current organizational context.',
                    $step->required_permission,
                ),
            );
        }
    }

    /**
     * INV-HR-LEAVE-007 — "No self approval where independent approver
     * required": approver_membership_id != employee.membership_id.
     */
    private function verifyIndependentApprover(
        string $tenantId,
        LeaveRequest $request,
        LeaveRequestApprovalStep $step,
        string $approverMembershipId,
    ): void {
        if (! $step->independent_approver) {
            return;
        }

        $employeeMembershipId = Employment::query()
            ->withoutGlobalScope('tenant')
            ->where('employments.id', $request->employment_id)
            ->where('employments.tenant_id', $tenantId)
            ->join('employees', 'employees.id', '=', 'employments.employee_id')
            ->value('employees.membership_id');

        if ($employeeMembershipId !== null && $employeeMembershipId === $approverMembershipId) {
            throw new LeaveLifecycleException(
                'LEAVE_SELF_APPROVAL_FORBIDDEN: approver cannot be the leave subject.',
            );
        }
    }

    private function requireActionableStatus(LeaveRequest $request): void
    {
        if (
            ! in_array(
                $request->status,
                [LeaveRequest::STATUS_SUBMITTED, LeaveRequest::STATUS_IN_REVIEW],
                true,
            )
        ) {
            throw new LeaveLifecycleException(
                sprintf(
                    'Leave Request [%s] is not actionable from status [%s].',
                    $request->id,
                    $request->status,
                ),
            );
        }
    }

    private function lockCurrentActionableStep(
        string $leaveRequestId,
        string $tenantId,
    ): LeaveRequestApprovalStep {
        // INV-HR-LEAVE-006 (Approval is sequential): step dengan
        // step_order TERKECIL yang masih PENDING adalah satu-satunya
        // yang actionable — kalau step sebelumnya belum diputuskan,
        // step ITU yang akan kembali di sini, bukan step berikutnya.
        $step = LeaveRequestApprovalStep::query()
            ->withoutGlobalScope('tenant')
            ->where('leave_request_id', $leaveRequestId)
            ->where('tenant_id', $tenantId)
            ->where('status', LeaveRequestApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();

        if ($step === null) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_APPROVAL_STEP_NOT_ACTIONABLE: no PENDING step for Leave Request [%s].',
                    $leaveRequestId,
                ),
            );
        }

        return $step;
    }

    private function lockRequestForTenant(
        string $leaveRequestId,
        string $tenantId,
    ): LeaveRequest {
        /** @var LeaveRequest|null $request */
        $request = LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $leaveRequestId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            throw (new ModelNotFoundException)->setModel(
                LeaveRequest::class,
                [$leaveRequestId],
            );
        }

        return $request;
    }

    private function auditSafely(
        string $tenantId,
        string $eventType,
        string $description,
        LeaveRequest $request,
        ?string $actorMembershipId,
    ): void {
        try {
            // INV-HR-LEAVE-014 — TANPA `reason` mentah.
            $this->auditTrail->log(
                eventType: $eventType,
                description: $description,
                tenantId: $tenantId,
                actorUserId: null,
                metadata: [
                    'leave_request_id' => $request->id,
                    'employment_id' => $request->employment_id,
                    'leave_type_id' => $request->leave_type_id,
                    'status' => $request->status,
                    'approval_policy_id' => $request->approval_policy_id,
                    'actor_membership_id' => $actorMembershipId,
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }
    }
}
