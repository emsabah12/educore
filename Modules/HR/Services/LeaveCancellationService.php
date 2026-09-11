<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveRequestEntitlementAllocation;
use Modules\HR\Models\LeaveType;
use Throwable;

/**
 * HR-004 §14.5 — Cancel approved request (8 langkah).
 *
 * Langkah 3 dokumen: "require hr.leave.cancel or equivalent authorized
 * capability/scope" — TIDAK ADA tabel kebijakan/scope terpisah untuk
 * cancellation di data dictionary (§7.x), berbeda dengan approval yang
 * punya `leave_approval_policies`/`leave_approval_policy_steps`. Jadi
 * pengecekan di sini murni permission tenant-wide, bukan mengarang
 * model scope baru yang tidak diminta dokumen.
 *
 * Langkah 4 dokumen: "apply temporal/business cancellation rules" —
 * dokumen TIDAK mendefinisikan aturan temporal konkret untuk Phase 2C
 * (mis. "harus dibatalkan N hari sebelum starts_at"), jadi saya tidak
 * mengarang aturan yang tidak berdasar. Titik ekstensi ini ditandai
 * eksplisit di kode sebagai TODO, bukan diam-diam dilewati.
 */
final readonly class LeaveCancellationService
{
    public function __construct(
        private AuthorizationServiceInterface $tenantAuthorization,
        private LeaveBalanceService $balanceService,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    /**
     * @throws LeaveLifecycleException LEAVE_CANCELLATION_NOT_ALLOWED.
     */
    public function cancelApproved(
        string $tenantId,
        string $leaveRequestId,
        string $actorMembershipId,
        ?string $reason = null,
    ): LeaveRequest {
        return DB::transaction(function () use (
            $tenantId,
            $leaveRequestId,
            $actorMembershipId,
        ): LeaveRequest {
            // §14.5 langkah 1.
            $request = $this->lockRequestForTenant($leaveRequestId, $tenantId);

            // §14.5 langkah 2.
            if ($request->status !== LeaveRequest::STATUS_APPROVED) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'LEAVE_CANCELLATION_NOT_ALLOWED: Leave Request [%s] is not APPROVED (current status [%s]).',
                        $request->id,
                        $request->status,
                    ),
                );
            }

            // §14.5 langkah 3.
            if (! $this->tenantAuthorization->hasPermission('hr.leave.cancel')) {
                throw new LeaveLifecycleException(
                    'LEAVE_CANCELLATION_NOT_ALLOWED: missing hr.leave.cancel permission.',
                );
            }

            // §14.5 langkah 4 — [TODO: Phase 2C tidak mendefinisikan
            // aturan temporal/bisnis konkret untuk cancellation; titik
            // ekstensi ini sengaja dibiarkan kosong, bukan diam-diam
            // dilewati].

            // §14.5 langkah 5.
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
                $allocations = LeaveRequestEntitlementAllocation::query()
                    ->withoutGlobalScope('tenant')
                    ->where('leave_request_id', $request->id)
                    ->where('tenant_id', $tenantId)
                    ->orderBy('entitlement_id')
                    ->get();

                // Lock SEMUA entitlement dalam urutan ID deterministik —
                // pola yang sama dengan §14.1 langkah 9.
                LeaveEntitlement::query()
                    ->withoutGlobalScope('tenant')
                    ->whereIn('id', $allocations->pluck('entitlement_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($allocations as $allocation) {
                    $this->balanceService->restoreForCancelledRequest(
                        $tenantId,
                        $allocation->entitlement_id,
                        $request->id,
                        (string) $allocation->allocated_units,
                    );
                }
            }

            // §14.5 langkah 6.
            $request->status = LeaveRequest::STATUS_CANCELLED;
            $request->cancelled_at = now();
            $request->save();

            // §14.5 langkah 7. INV-HR-LEAVE-014 — TANPA `reason` mentah.
            $this->auditSafely($tenantId, $request, $actorMembershipId);

            // §14.5 langkah 8 — publikasi event post-commit untuk
            // konsumen downstream: BELUM ada integrasi event bus di
            // Phase 2C (§20 Integration Boundary belum mendefinisikan
            // konsumen konkret), jadi tidak diimplementasikan sebagai
            // panggilan nyata di sini.

            return $request->refresh();
        });
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
        LeaveRequest $request,
        string $actorMembershipId,
    ): void {
        try {
            $this->auditTrail->log(
                eventType: 'hr.leave.request.cancelled',
                description: 'Leave Request cancelled.',
                tenantId: $tenantId,
                actorUserId: null,
                metadata: [
                    'leave_request_id' => $request->id,
                    'employment_id' => $request->employment_id,
                    'leave_type_id' => $request->leave_type_id,
                    'actor_membership_id' => $actorMembershipId,
                ],
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }
    }
}
