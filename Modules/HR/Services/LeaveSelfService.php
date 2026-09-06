<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Support\Collection;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Employment;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveRequest;

/**
 * HR-004 §15.7 — Self-service.
 *
 * "Employee identity is resolved from authenticated Membership →
 * Employee. Client cannot request another employee_id through self
 * routes." SETIAP method di sini mengambil `membershipId` dari
 * Membership terautentikasi — TIDAK PERNAH dari input client.
 */
final readonly class LeaveSelfService
{
    public function __construct(
        private LeaveRequestService $requestService,
        private LeaveBalanceService $balanceService,
    ) {}

    /**
     * @return Collection<int, array{
     *     entitlement_id: string,
     *     leave_type_id: string,
     *     period_start: string,
     *     period_end: string,
     *     status: string,
     *     balance: string,
     * }>
     */
    public function ownBalances(string $tenantId, string $membershipId): Collection
    {
        $employmentId = $this->resolveActiveEmploymentId($tenantId, $membershipId);

        return LeaveEntitlement::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('period_start')
            ->get()
            ->map(fn(LeaveEntitlement $entitlement): array => [
                'entitlement_id' => $entitlement->id,
                'leave_type_id' => $entitlement->leave_type_id,
                'period_start' => $entitlement->period_start->toDateString(),
                'period_end' => $entitlement->period_end->toDateString(),
                'status' => $entitlement->status,
                'balance' => $this->balanceService->balance($tenantId, $entitlement->id),
            ]);
    }

    /**
     * @return Collection<int, LeaveRequest>
     */
    public function ownHistory(string $tenantId, string $membershipId): Collection
    {
        $employmentId = $this->resolveActiveEmploymentId($tenantId, $membershipId);

        return $this->requestService->getHistory($tenantId, $employmentId);
    }

    public function createOwnDraft(
        string $tenantId,
        string $membershipId,
        string $leaveTypeId,
        string $startsAt,
        string $endsAt,
        string $requestTimezone,
        string $requestedUnits,
        ?string $reason,
    ): LeaveRequest {
        $employmentId = $this->resolveActiveEmploymentId($tenantId, $membershipId);

        return $this->requestService->createDraft(
            $tenantId,
            $employmentId,
            $leaveTypeId,
            $startsAt,
            $endsAt,
            $requestTimezone,
            $requestedUnits,
            $reason,
        );
    }

    /**
     * @throws LeaveLifecycleException LEAVE_SELF_REQUEST_NOT_OWNED kalau
     *                                   Leave Request bukan milik
     *                                   Employment si aktor.
     */
    public function submitOwn(string $tenantId, string $membershipId, string $leaveRequestId): LeaveRequest
    {
        $this->assertOwnsRequest($tenantId, $membershipId, $leaveRequestId);

        return $this->requestService->submit($tenantId, $leaveRequestId, $membershipId);
    }

    public function withdrawOwn(string $tenantId, string $membershipId, string $leaveRequestId): LeaveRequest
    {
        $this->assertOwnsRequest($tenantId, $membershipId, $leaveRequestId);

        return $this->requestService->withdraw($tenantId, $leaveRequestId);
    }

    /**
     * @throws LeaveLifecycleException LEAVE_SELF_REQUEST_NOT_OWNED.
     */
    public function findOwn(string $tenantId, string $membershipId, string $leaveRequestId): LeaveRequest
    {
        $this->assertOwnsRequest($tenantId, $membershipId, $leaveRequestId);

        return LeaveRequest::query()
            ->with('approvalSteps', 'entitlementAllocations')
            ->where('id', $leaveRequestId)
            ->firstOrFail();
    }

    private function assertOwnsRequest(string $tenantId, string $membershipId, string $leaveRequestId): void
    {
        $employmentId = $this->resolveActiveEmploymentId($tenantId, $membershipId);

        $owned = LeaveRequest::query()
            ->where('id', $leaveRequestId)
            ->where('employment_id', $employmentId)
            ->exists();

        if (! $owned) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_SELF_REQUEST_NOT_OWNED: Leave Request [%s] does not belong to the authenticated Membership [%s].',
                    $leaveRequestId,
                    $membershipId,
                ),
            );
        }
    }

    /**
     * @throws LeaveLifecycleException LEAVE_SELF_NO_ACTIVE_EMPLOYMENT.
     */
    private function resolveActiveEmploymentId(string $tenantId, string $membershipId): string
    {
        $employee = Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)
            ->first();

        $employmentId = $employee?->employments()
            ->where('status', Employment::STATUS_ACTIVE)
            ->value('id');

        if ($employmentId === null) {
            throw new LeaveLifecycleException(
                sprintf(
                    'LEAVE_SELF_NO_ACTIVE_EMPLOYMENT: Membership [%s] has no active Employee/Employment record.',
                    $membershipId,
                ),
            );
        }

        return $employmentId;
    }
}
