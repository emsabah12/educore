<?php

namespace Modules\Finance\Application\Cash;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Saldo rekening kas (PRD-004 §3): saldo awal + MASUK − KELUAR yang SAH. Tidak pernah disimpan, selalu dihitung.
 */
final class CashBalances
{
    public function balance(string $accountId): int
    {
        return $this->balances([$accountId])[$accountId] ?? 0;
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, int> id rekening → saldo
     */
    public function balances(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $balances = [];

        foreach (DB::table('fn_cash_accounts')->whereIn('id', $accountIds)->get(['id', 'opening_balance']) as $account) {
            $balances[(string) $account->id] = (int) $account->opening_balance;
        }

        foreach (DB::table('fn_cash_entries')
            ->whereIn('account_id', $accountIds)
            ->where('status', PaymentStatus::Valid->value)
            ->groupBy('account_id', 'direction')
            ->get(['account_id', 'direction', DB::raw('SUM(amount) AS total')]) as $row) {
            $sign = (string) $row->direction === CashDirection::In->value ? 1 : -1;
            $balances[(string) $row->account_id] = ($balances[(string) $row->account_id] ?? 0) + $sign * (int) $row->total;
        }

        return $balances;
    }
}
