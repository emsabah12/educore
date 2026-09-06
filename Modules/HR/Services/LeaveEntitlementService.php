<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\LeaveBalanceLedger;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveType;

/**
 * HR-004 §13.1 — `LeaveEntitlementService`. `closePeriod()`/
 * `carryOver()` menyusul di step terpisah (butuh logika periode-akhir
 * yang lebih kompleks); step ini fokus pada pembangkitan awal.
 */
final readonly class LeaveEntitlementService
{
    public function __construct(
        private LeaveEntitlementPolicyService $policyService,
        private LeaveBalanceService $balanceService,
    ) {}

    /**
     * §12.1 "Baseline grant": resolve kebijakan yang berlaku, buat
     * bucket entitlement, tulis GRANT pertama. Idempotency key
     * deterministik dari entitlement — kalau method ini entah bagaimana
     * dipanggil ulang untuk periode yang sama, UNIQUE constraint di
     * kedua tabel (bucket period, ledger idempotency) sama-sama
     * menolaknya, bukan menggandakan grant.
     */
    public function generateForPeriod(
        string $tenantId,
        string $employmentId,
        string $leaveTypeId,
        string $periodStart,
        string $periodEnd,
    ): LeaveEntitlement {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $leaveTypeId,
            $periodStart,
            $periodEnd,
        ): LeaveEntitlement {
            $policy = $this->policyService->resolve(
                $tenantId,
                $leaveTypeId,
                $employmentId,
                $periodStart,
            );

            try {
                $entitlement = LeaveEntitlement::create([
                    'employment_id' => $employmentId,
                    'leave_type_id' => $leaveTypeId,
                    'entitlement_policy_id' => $policy->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);
            } catch (QueryException $exception) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'Entitlement for Employment [%s], LeaveType [%s], period [%s..%s] already exists.',
                        $employmentId,
                        $leaveTypeId,
                        $periodStart,
                        $periodEnd,
                    ),
                    previous: $exception,
                );
            }

            LeaveBalanceLedger::create([
                'entitlement_id' => $entitlement->id,
                'entry_type' => LeaveBalanceLedger::ENTRY_GRANT,
                'units_delta' => $policy->grant_units,
                'idempotency_key' => sprintf('grant:%s', $entitlement->id),
                'occurred_at' => now(),
            ]);

            return $entitlement->refresh();
        });
    }

    /**
     * Override administratif — dibuat TANPA melalui resolusi kebijakan
     * (mis. kasus khusus/migrasi data), sehingga `entitlement_policy_id`
     * NULL sebagai jejak bahwa ini bukan hasil kebijakan otomatis.
     */
    public function createManualEntitlement(
        string $tenantId,
        string $employmentId,
        string $leaveTypeId,
        string $periodStart,
        string $periodEnd,
        string $grantUnits,
        ?string $actorMembershipId = null,
        ?string $note = null,
    ): LeaveEntitlement {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $leaveTypeId,
            $periodStart,
            $periodEnd,
            $grantUnits,
            $actorMembershipId,
            $note,
        ): LeaveEntitlement {
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

            if (! $leaveType->isBalanceBacked()) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'LeaveType [%s] is not BALANCE-backed; a manual entitlement would never apply.',
                        $leaveType->id,
                    ),
                );
            }

            try {
                $entitlement = LeaveEntitlement::create([
                    'employment_id' => $employmentId,
                    'leave_type_id' => $leaveTypeId,
                    'entitlement_policy_id' => null,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);
            } catch (QueryException $exception) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'Entitlement for Employment [%s], LeaveType [%s], period [%s..%s] already exists.',
                        $employmentId,
                        $leaveTypeId,
                        $periodStart,
                        $periodEnd,
                    ),
                    previous: $exception,
                );
            }

            LeaveBalanceLedger::create([
                'entitlement_id' => $entitlement->id,
                'entry_type' => LeaveBalanceLedger::ENTRY_GRANT,
                'units_delta' => $grantUnits,
                'idempotency_key' => sprintf('grant:%s', $entitlement->id),
                'actor_membership_id' => $actorMembershipId,
                'note' => $note,
                'occurred_at' => now(),
            ]);

            return $entitlement->refresh();
        });
    }
}
