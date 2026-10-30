<?php

namespace Modules\Finance\Application\Cash;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Buku kas (PRD-004 §6, KS-1): rekening + saldo, dan transaksi satu bulan dengan saldo berjalan. Hak akses
 * dicek pemanggil. Kas = satu LEMBAGA atau kas Yayasan ($organizationId null).
 *
 * Saldo awal rekening berlaku per AWAL tanggal saldo awalnya: rekening yang saldo awalnya jatuh di bulan yang
 * dilihat tampil sebagai baris "Saldo awal" pada tanggal itu. Transaksi batal tetap tampil (dicoret) tetapi
 * tidak dihitung.
 *
 * @phpstan-type AccountRow array{id: string, kind: string, name: string, bank_name: string|null, account_number: string|null, opening_balance: int, opening_date: string, is_active: bool, receives_transfers: bool, balance: int, has_entries: bool}
 * @phpstan-type LedgerRow array{id: string, type: string, date: string, description: string, category: string|null, account: string, direction: string, amount: int, status: string, void_reason: string|null, source: string|null, source_id: string|null, recorded_by: string|null, balance: int|null}
 */
final class CashBook
{
    public function __construct(
        private readonly CashBalances $balances,
    ) {}

    /**
     * Rekening kas (Kas tunai dulu, lalu bank urut nama) beserta saldonya.
     *
     * @return list<AccountRow>
     */
    public function accounts(string $tenantId, ?string $organizationId): array
    {
        $rows = $this->accountQuery($tenantId, $organizationId)
            ->orderByRaw("kind <> 'TUNAI'")
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'kind', 'name', 'bank_name', 'account_number', 'opening_balance', 'opening_date', 'is_active', 'receives_transfers']);

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (string) $row->id;
        }

        $balances = $this->balances->balances($ids);
        $used = [];

        // Cerminan kuitansi kasir (KS-2) tidak mengunci saldo awal.
        foreach (DB::table('fn_cash_entries')->whereIn('account_id', $ids)->where('source', '!=', CashEntrySource::Payment->value)->distinct()->pluck('account_id') as $id) {
            $used[(string) $id] = true;
        }

        $accounts = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $accounts[] = [
                'id' => $id,
                'kind' => (string) $row->kind,
                'name' => (string) $row->name,
                'bank_name' => $row->bank_name === null ? null : (string) $row->bank_name,
                'account_number' => $row->account_number === null ? null : (string) $row->account_number,
                'opening_balance' => (int) $row->opening_balance,
                'opening_date' => substr((string) $row->opening_date, 0, 10),
                'is_active' => (bool) $row->is_active,
                'receives_transfers' => (bool) $row->receives_transfers,
                'balance' => $balances[$id] ?? 0,
                'has_entries' => isset($used[$id]),
            ];
        }

        return $accounts;
    }

    /**
     * Buku kas satu bulan (Y-m), semua rekening kas itu atau satu rekening.
     *
     * @return array{opening: int, rows: list<LedgerRow>, total_in: int, total_out: int, transfer_in: int, transfer_out: int, closing: int}
     */
    public function ledger(string $tenantId, ?string $organizationId, string $month, ?string $accountId): array
    {
        $first = CarbonImmutable::parse($month.'-01');
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();

        $accounts = $this->accountQuery($tenantId, $organizationId)
            ->when($accountId !== null, fn (Builder $query) => $query->where('id', $accountId))
            ->get(['id', 'name', 'opening_balance', 'opening_date']);

        $ids = [];
        $names = [];
        $opening = 0;
        $rows = [];

        foreach ($accounts as $account) {
            $id = (string) $account->id;
            $ids[] = $id;
            $names[$id] = (string) $account->name;
            $openingDate = substr((string) $account->opening_date, 0, 10);

            if ($openingDate < $from) {
                $opening += (int) $account->opening_balance;
            } elseif ($openingDate <= $to) {
                $rows[] = [
                    'id' => 'opening-'.$id,
                    'type' => 'opening',
                    'date' => $openingDate,
                    'description' => 'Saldo awal '.$names[$id],
                    'category' => null,
                    'account' => $names[$id],
                    'direction' => CashDirection::In->value,
                    'amount' => (int) $account->opening_balance,
                    'status' => PaymentStatus::Valid->value,
                    'void_reason' => null,
                    'source' => null,
                    'source_id' => null,
                    'recorded_by' => null,
                    'sort' => $openingDate.' 0',
                ];
            }
        }

        if ($ids !== []) {
            foreach (DB::table('fn_cash_entries')
                ->whereIn('account_id', $ids)
                ->where('entry_date', '<', $from)
                ->where('status', PaymentStatus::Valid->value)
                ->groupBy('direction')
                ->get(['direction', DB::raw('SUM(amount) AS total')]) as $row) {
                $opening += ((string) $row->direction === CashDirection::In->value ? 1 : -1) * (int) $row->total;
            }

            foreach (DB::table('fn_cash_entries as e')
                ->join('fn_cash_categories as c', 'c.id', '=', 'e.category_id')
                ->leftJoin('memberships as m', 'm.id', '=', 'e.recorded_by_membership_id')
                ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
                ->whereIn('e.account_id', $ids)
                ->whereBetween('e.entry_date', [$from, $to])
                ->orderBy('e.entry_date')
                ->orderBy('e.created_at')
                ->orderBy('e.id')
                ->get(['e.id', 'e.account_id', 'e.entry_date', 'e.direction', 'e.amount', 'e.description', 'e.source', 'e.source_id', 'e.status', 'e.void_reason', 'e.created_at', 'c.name as category', 'p.name as recorded_by']) as $entry) {
                $date = substr((string) $entry->entry_date, 0, 10);
                $rows[] = [
                    'id' => (string) $entry->id,
                    'type' => 'entry',
                    'date' => $date,
                    'description' => (string) $entry->description,
                    'category' => (string) $entry->category,
                    'account' => $names[(string) $entry->account_id] ?? '',
                    'direction' => (string) $entry->direction,
                    'amount' => (int) $entry->amount,
                    'status' => (string) $entry->status,
                    'void_reason' => $entry->void_reason === null ? null : (string) $entry->void_reason,
                    'source' => (string) $entry->source,
                    'source_id' => $entry->source_id === null ? null : (string) $entry->source_id,
                    'recorded_by' => $entry->recorded_by === null ? null : (string) $entry->recorded_by,
                    'sort' => $date.' 1 '.(string) $entry->created_at.' '.(string) $entry->id,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        $balance = $opening;
        $in = 0;
        $out = 0;
        $transferIn = 0;
        $transferOut = 0;
        $ledger = [];

        foreach ($rows as $row) {
            unset($row['sort']);
            $counted = $row['status'] === PaymentStatus::Valid->value;

            if ($counted) {
                // Mutasi (KS-4) bukan pemasukan/pengeluaran: dihitung terpisah.
                $transfer = $row['source'] === CashEntrySource::Transfer->value;

                if ($row['direction'] === CashDirection::In->value) {
                    $balance += $row['amount'];

                    if ($transfer) {
                        $transferIn += $row['amount'];
                    } elseif ($row['type'] === 'entry') {
                        // Saldo awal rekening bukan pemasukan bulan ini.
                        $in += $row['amount'];
                    }
                } else {
                    $balance -= $row['amount'];

                    if ($transfer) {
                        $transferOut += $row['amount'];
                    } else {
                        $out += $row['amount'];
                    }
                }
            }

            $ledger[] = $row + ['balance' => $counted ? $balance : null];
        }

        return ['opening' => $opening, 'rows' => $ledger, 'total_in' => $in, 'total_out' => $out, 'transfer_in' => $transferIn, 'transfer_out' => $transferOut, 'closing' => $balance];
    }

    private function accountQuery(string $tenantId, ?string $organizationId): Builder
    {
        return DB::table('fn_cash_accounts')
            ->where('tenant_id', $tenantId)
            ->when($organizationId === null, fn (Builder $query) => $query->whereNull('organization_id'), fn (Builder $query) => $query->where('organization_id', $organizationId));
    }
}
