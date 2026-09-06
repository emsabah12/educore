<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Models\LeaveApprovalPolicy;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveRequestApprovalStep;
use Modules\HR\Models\LeaveType;

/**
 * HR-004 §13.1 — `LeaveRequestService`.
 *
 * `submit()` mengimplementasikan §10 langkah 1-13. Untuk
 * `decision_mode=AUTO`, dokumen bilang "the service immediately runs
 * the same final-approval validation" — sekarang `LeaveApprovalService`
 * sudah ada dan teruji, jadi submit() langsung memanggil
 * `finalizeApproval()` untuk kebijakan AUTO alih-alih berhenti di
 * SUBMITTED.
 */
final readonly class LeaveRequestService
{
    public function __construct(
        private LeaveApprovalPolicyService $approvalPolicyService,
        private LeaveApprovalService $approvalService,
    ) {}

    /**
     * INV-HR-LEAVE-003 (Unit consistency): `unit` SELALU disalin dari
     * LeaveType saat ini — request tidak pernah menerima unit dari
     * input pemanggil secara independen.
     */
    public function createDraft(
        string $tenantId,
        string $employmentId,
        string $leaveTypeId,
        string $startsAt,
        string $endsAt,
        string $requestTimezone,
        string $requestedUnits,
        ?string $reason = null,
    ): LeaveRequest {
        $leaveType = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $leaveTypeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($leaveType === null) {
            throw (new ModelNotFoundException())->setModel(
                LeaveType::class,
                [$leaveTypeId],
            );
        }

        return LeaveRequest::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'request_timezone' => $requestTimezone,
            'requested_units' => $requestedUnits,
            'unit' => $leaveType->unit,
            'reason' => $reason,
        ]);
    }

    /**
     * §15.5 — "PATCH /leave-requests/{id} — DRAFT only." `status` TIDAK
     * PERNAH diterima sebagai field yang bisa diubah lewat method ini —
     * transisi status HANYA lewat submit()/withdraw()/finalize().
     *
     * @param array{
     *     leave_type_id?: string,
     *     starts_at?: string,
     *     ends_at?: string,
     *     request_timezone?: string,
     *     requested_units?: string,
     *     reason?: string|null,
     * } $data
     */
    public function updateDraft(string $tenantId, string $leaveRequestId, array $data): LeaveRequest
    {
        $request = $this->lockRequestForTenant($leaveRequestId, $tenantId);

        if ($request->status !== LeaveRequest::STATUS_DRAFT) {
            throw new LeaveLifecycleException(
                sprintf(
                    'Leave Request [%s] can only be updated while DRAFT (current status [%s]).',
                    $request->id,
                    $request->status,
                ),
            );
        }

        // INV-HR-LEAVE-003: kalau leave_type_id diganti, `unit` HARUS
        // disinkronkan ulang dari LeaveType yang baru — bukan dibiarkan
        // basi dari LeaveType sebelumnya.
        if (array_key_exists('leave_type_id', $data)) {
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

            $data['unit'] = $leaveType->unit;
        }

        $request->fill($data);
        $request->save();

        return $request->refresh();
    }

    /**
     * §10 langkah 1-13 (decision_mode=SEQUENTIAL).
     *
     * @throws LeaveLifecycleException LEAVE_EMPLOYMENT_NOT_ACTIVE,
     *                                   LEAVE_TYPE_INACTIVE, atau apa
     *                                   pun yang dilempar resolusi
     *                                   Approval Policy.
     */
    public function submit(
        string $tenantId,
        string $leaveRequestId,
        string $submittedByMembershipId,
    ): LeaveRequest {
        return DB::transaction(function () use (
            $tenantId,
            $leaveRequestId,
            $submittedByMembershipId,
        ): LeaveRequest {
            // §10 langkah 1: lock request.
            $request = $this->lockRequestForTenant($leaveRequestId, $tenantId);

            if ($request->status !== LeaveRequest::STATUS_DRAFT) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'Leave Request [%s] cannot be submitted from status [%s].',
                        $request->id,
                        $request->status,
                    ),
                );
            }

            // §10 langkah 1 (lanjutan) + INV-HR-LEAVE-001: Employment ACTIVE.
            $employment = Employment::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $request->employment_id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($employment === null) {
                throw (new ModelNotFoundException())->setModel(
                    Employment::class,
                    [$request->employment_id],
                );
            }

            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'LEAVE_EMPLOYMENT_NOT_ACTIVE: Employment [%s] is not ACTIVE.',
                        $employment->id,
                    ),
                );
            }

            // §10 langkah 4 + INV-HR-LEAVE-002: Leave Type harus aktif
            // SAAT SUBMIT (bukan saat draft dibuat).
            $leaveType = LeaveType::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $request->leave_type_id)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($leaveType === null || ! $leaveType->is_active) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'LEAVE_TYPE_INACTIVE: LeaveType [%s] is not active.',
                        $request->leave_type_id,
                    ),
                );
            }

            // §10 langkah 5: approval_context_placement_id dari
            // Employment Placement aktif (open + primary).
            $openPrimaryPlacement = EmploymentPlacement::query()
                ->withoutGlobalScope('tenant')
                ->where('employment_id', $employment->id)
                ->where('tenant_id', $tenantId)
                ->whereNull('effective_to')
                ->where('is_primary', true)
                ->first();

            // §10 langkah 6-10: resolve Approval Policy (melempar
            // LEAVE_APPROVAL_POLICY_NOT_FOUND/_AMBIGUOUS kalau perlu).
            $policy = $this->approvalPolicyService->resolve(
                $tenantId,
                $request->leave_type_id,
                $employment->id,
                now()->toDateString(),
            );

            // §10 langkah 11.
            $request->approval_context_placement_id = $openPrimaryPlacement?->id;
            $request->approval_policy_id = $policy->id;
            $request->submitted_by_membership_id = $submittedByMembershipId;
            $request->submitted_at = now();

            if ($policy->decision_mode === LeaveApprovalPolicy::DECISION_MODE_AUTO) {
                // "no manual steps are generated" — request langsung
                // difinalisasi lewat jalur yang SAMA PERSIS dengan
                // final approval SEQUENTIAL (LeaveApprovalService),
                // bukan jalur pintas terpisah.
                $request->status = LeaveRequest::STATUS_SUBMITTED;
                $request->save();

                return $this->approvalService->finalizeApproval(
                    $tenantId,
                    $request->id,
                    $submittedByMembershipId,
                );
            }

            // §10 langkah 12: snapshot policy steps.
            foreach ($policy->steps as $policyStep) {
                LeaveRequestApprovalStep::create([
                    'leave_request_id' => $request->id,
                    'policy_step_id' => $policyStep->id,
                    'step_order' => $policyStep->step_order,
                    'required_permission' => $policyStep->required_permission,
                    'scope_strategy' => $policyStep->scope_strategy,
                    'independent_approver' => $policyStep->independent_approver,
                ]);
            }

            // §10 langkah 13.
            $request->status = LeaveRequest::STATUS_SUBMITTED;
            $request->save();

            return $request->refresh();
        });
    }

    /**
     * §14.4 — "No ledger mutation occurs." Karena Phase 2C mengonsumsi
     * saldo HANYA di final approval, withdraw sebelum itu tidak pernah
     * menyentuh ledger.
     */
    public function withdraw(string $tenantId, string $leaveRequestId): LeaveRequest
    {
        return DB::transaction(function () use ($tenantId, $leaveRequestId): LeaveRequest {
            $request = $this->lockRequestForTenant($leaveRequestId, $tenantId);

            $withdrawableStatuses = [
                LeaveRequest::STATUS_DRAFT,
                LeaveRequest::STATUS_SUBMITTED,
                LeaveRequest::STATUS_IN_REVIEW,
            ];

            if (! in_array($request->status, $withdrawableStatuses, true)) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'Leave Request [%s] cannot be withdrawn from status [%s].',
                        $request->id,
                        $request->status,
                    ),
                );
            }

            $request->status = LeaveRequest::STATUS_WITHDRAWN;
            $request->withdrawn_at = now();
            $request->save();

            return $request->refresh();
        });
    }

    /**
     * @return Collection<int, LeaveRequest>
     */
    public function getHistory(string $tenantId, string $employmentId): Collection
    {
        return LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->orderByDesc('created_at')
            ->get();
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
            throw (new ModelNotFoundException())->setModel(
                LeaveRequest::class,
                [$leaveRequestId],
            );
        }

        return $request;
    }
}
