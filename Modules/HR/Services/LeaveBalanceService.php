<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\LeaveBalanceLedger;
use Modules\HR\Models\LeaveEntitlement;

/**
 * HR-004 §7.4 / §13.1 — "final balance = SUM(units_delta) for
 * entitlement." Service ini satu-satunya jalur penulisan ledger untuk
 * operasi non-request (adjust); consumeForApprovedRequest() dan
 * restoreForCancelledRequest() menyusul di Fase D (butuh leave_requests).
 */
final readonly class LeaveBalanceService
{
    public function balance(string $tenantId, string $entitlementId): string
    {
        $sum = LeaveBalanceLedger::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('entitlement_id', $entitlementId)
            ->sum('units_delta');

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * Koreksi administratif manual. `$idempotencyKey` WAJIB diisi
     * pemanggil — dokumen menekankan "stable semantic operation key",
     * bukan sesuatu yang di-generate acak oleh service ini (supaya
     * retry dari pemanggil yang sama benar-benar terdeteksi sebagai
     * duplikat oleh UNIQUE(tenant_id, idempotency_key)).
     *
     * "negative resulting balance is rejected in Phase 2C."
     */
    public function adjust(
        string $tenantId,
        string $entitlementId,
        string $unitsDelta,
        string $idempotencyKey,
        ?string $actorMembershipId = null,
        ?string $note = null,
    ): LeaveBalanceLedger {
        return DB::transaction(function () use (
            $tenantId,
            $entitlementId,
            $unitsDelta,
            $idempotencyKey,
            $actorMembershipId,
            $note,
        ): LeaveBalanceLedger {
            // Lock baris Entitlement sebagai mutex logis — ledger
            // sendiri append-only (tidak ada baris "current state" untuk
            // dikunci), jadi baris induk inilah yang menyerialkan
            // operasi bersaing terhadap bucket yang sama.
            $entitlement = LeaveEntitlement::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $entitlementId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($entitlement === null) {
                throw (new ModelNotFoundException())->setModel(
                    LeaveEntitlement::class,
                    [$entitlementId],
                );
            }

            $currentBalance = (float) $this->balance($tenantId, $entitlementId);
            $resultingBalance = $currentBalance + (float) $unitsDelta;

            if ($resultingBalance < 0) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'Adjustment would result in negative balance (%s) for Entitlement [%s].',
                        number_format($resultingBalance, 2),
                        $entitlementId,
                    ),
                );
            }

            try {
                return LeaveBalanceLedger::create([
                    'entitlement_id' => $entitlementId,
                    'entry_type' => LeaveBalanceLedger::ENTRY_ADJUSTMENT,
                    'units_delta' => $unitsDelta,
                    'idempotency_key' => $idempotencyKey,
                    'actor_membership_id' => $actorMembershipId,
                    'note' => $note,
                    'occurred_at' => now(),
                ]);
            } catch (QueryException $exception) {
                // UNIQUE(tenant_id, idempotency_key) — retry dengan key
                // yang sama TIDAK PERNAH menerapkan penyesuaian dua kali.
                throw new LeaveLifecycleException(
                    sprintf(
                        'Adjustment with idempotency key [%s] was already applied.',
                        $idempotencyKey,
                    ),
                    previous: $exception,
                );
            }
        });
    }

    /**
     * INV-HR-LEAVE-010 — "Exactly one semantic CONSUME ledger effect
     * may be produced per (request, entitlement allocation)." Kunci
     * idempotensi deterministik dari (request, entitlement) — retry
     * dengan pasangan yang sama TIDAK PERNAH mendebit dua kali.
     */
    public function consumeForApprovedRequest(
        string $tenantId,
        string $entitlementId,
        string $leaveRequestId,
        string $unitsToConsume,
    ): LeaveBalanceLedger {
        return DB::transaction(function () use (
            $tenantId,
            $entitlementId,
            $leaveRequestId,
            $unitsToConsume,
        ): LeaveBalanceLedger {
            $idempotencyKey = sprintf('consume:%s:%s', $leaveRequestId, $entitlementId);

            $existing = LeaveBalanceLedger::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $entitlement = LeaveEntitlement::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $entitlementId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($entitlement === null) {
                throw (new ModelNotFoundException())->setModel(
                    LeaveEntitlement::class,
                    [$entitlementId],
                );
            }

            $currentBalance = (float) $this->balance($tenantId, $entitlementId);

            if ($currentBalance < (float) $unitsToConsume) {
                throw new LeaveLifecycleException(
                    sprintf(
                        'LEAVE_INSUFFICIENT_BALANCE: Entitlement [%s] has balance [%s], cannot consume [%s].',
                        $entitlementId,
                        number_format($currentBalance, 2),
                        $unitsToConsume,
                    ),
                );
            }

            return LeaveBalanceLedger::create([
                'entitlement_id' => $entitlementId,
                'entry_type' => LeaveBalanceLedger::ENTRY_CONSUME,
                'units_delta' => '-' . $unitsToConsume,
                'leave_request_id' => $leaveRequestId,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * INV-HR-LEAVE-011 — "Approved balance-backed cancellation produces
     * one idempotent RESTORE per consumed entitlement allocation."
     */
    public function restoreForCancelledRequest(
        string $tenantId,
        string $entitlementId,
        string $leaveRequestId,
        string $unitsToRestore,
    ): LeaveBalanceLedger {
        $idempotencyKey = sprintf('restore:%s:%s', $leaveRequestId, $entitlementId);

        $existing = LeaveBalanceLedger::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LeaveBalanceLedger::create([
            'entitlement_id' => $entitlementId,
            'entry_type' => LeaveBalanceLedger::ENTRY_RESTORE,
            'units_delta' => $unitsToRestore,
            'leave_request_id' => $leaveRequestId,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
    }
}
