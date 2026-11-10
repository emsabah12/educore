<?php

namespace Modules\Finance\Application\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\Ledger\AccountGroup;
use Modules\Finance\Domain\Ledger\CashFlowActivity;
use Modules\Finance\Domain\Ledger\JournalSource;
use stdClass;

/**
 * Laporan keuangan format ISAK 35 entitas nirlaba (PRD-007 §7, PB-3). Hanya jurnal SAH; lingkup & hak akses dicek
 * pemanggil. Lingkup: null = gabungan; '' = Yayasan pusat; id = satu lembaga.
 *
 * Aturan [ASUMSI PB-3]:
 * - Akun dikelompokkan menurut akun judul induknya (akun tanpa induk → "Lainnya"); akun bersaldo nol di semua kolom
 *   tidak ditampilkan.
 * - Aset neto tanpa pembatasan = saldo akun aset neto tanpa pembatasan + akumulasi pendapatan − beban tanpa pembatasan
 *   sampai tanggal laporan (tanpa jurnal tutup buku); dengan pembatasan = saldo akun aset neto bertanda "dengan
 *   pembatasan" + akumulasi pendapatan bertanda "dengan pembatasan".
 * - "Aset neto yang dibebaskan dari pembatasan" = jurnal yang memindahkan saldo dari akun aset neto dengan pembatasan ke
 *   akun aset neto tanpa pembatasan (jurnal manual).
 * - Arus kas metode langsung: hanya akun kas/bank selain Kas titipan tabungan (dipisah sebagai catatan). Tiap jurnal yang
 *   menggerakkan kas dirinci per akun lawan menurut aktivitas arus kas akun itu (kosong = operasi); mutasi antar-akun
 *   kas tidak dihitung. Saldo awal rekening yang dibuka dalam periode ditampilkan terpisah.
 * - Kolom pembanding: posisi keuangan per akhir tahun buku lalu; penghasilan komprehensif periode yang sama tahun lalu —
 *   hanya bila tanggalnya tidak sebelum tanggal mulai pembukuan.
 *
 * @phpstan-import-type Period from LedgerReports
 *
 * @phpstan-type Line array{code: string, name: string, current: int, previous: int|null}
 * @phpstan-type Pair array{current: int, previous: int|null}
 * @phpstan-type Section array{title: string, lines: list<Line>, current: int, previous: int|null}
 * @phpstan-type Position array{date: string, previous_date: string|null, assets: list<Section>, liabilities: list<Section>, net_assets: array{unrestricted: Pair, restricted: Pair}, total_assets: Pair, total_liabilities: Pair, total_net_assets: Pair}
 * @phpstan-type Activities array{from: string, to: string, previous_from: string|null, previous_to: string|null, unrestricted: array{revenue: list<Section>, released: Pair, revenue_total: Pair, expenses: list<Section>, expense_total: Pair, surplus: Pair}, restricted: array{revenue: list<Section>, released: Pair, change: Pair}, total: Pair}
 * @phpstan-type Changes array{unrestricted: array{opening: int, change: int, other: int, closing: int}, restricted: array{opening: int, change: int, other: int, closing: int}, total: array{opening: int, change: int, other: int, closing: int}}
 * @phpstan-type CashFlows array{activities: list<array{activity: string, label: string, receipts: list<array{code: string, name: string, amount: int}>, payments: list<array{code: string, name: string, amount: int}>, net: int}>, net_change: int, new_accounts: int, opening: int, closing: int, custody: array{opening: int, closing: int}}
 */
final class FinancialStatements
{
    public function __construct(
        private readonly ChartOfAccounts $accounts,
    ) {}

    /**
     * @param  Period  $period
     * @return Position
     */
    public function position(string $tenantId, ?string $scope, array $period): array
    {
        $accounts = $this->accountMap($tenantId);
        $previousDate = CarbonImmutable::parse($period['fiscal_start'])->subDay()->toDateString();
        $previousDate = $previousDate >= $period['start_date'] ? $previousDate : null;
        $current = $this->balances($tenantId, $scope, null, $period['to']);
        $previous = $previousDate === null ? null : $this->balances($tenantId, $scope, null, $previousDate);

        $assets = $this->sections($accounts, AccountGroup::Asset, $current, $previous, 1);
        $liabilities = $this->sections($accounts, AccountGroup::Liability, $current, $previous, -1);
        $netCurrent = $this->netAssets($accounts, $current);
        $netPrevious = $previous === null ? null : $this->netAssets($accounts, $previous);

        return [
            'date' => $period['to'],
            'previous_date' => $previousDate,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'net_assets' => [
                'unrestricted' => ['current' => $netCurrent['unrestricted'], 'previous' => $netPrevious['unrestricted'] ?? null],
                'restricted' => ['current' => $netCurrent['restricted'], 'previous' => $netPrevious['restricted'] ?? null],
            ],
            'total_assets' => $this->total($assets, $previous !== null),
            'total_liabilities' => $this->total($liabilities, $previous !== null),
            'total_net_assets' => [
                'current' => $netCurrent['unrestricted'] + $netCurrent['restricted'],
                'previous' => $netPrevious === null ? null : $netPrevious['unrestricted'] + $netPrevious['restricted'],
            ],
        ];
    }

    /**
     * @param  Period  $period
     * @return Activities
     */
    public function activities(string $tenantId, ?string $scope, array $period): array
    {
        $accounts = $this->accountMap($tenantId);
        $previousFrom = max(CarbonImmutable::parse($period['from'])->subYear()->toDateString(), $period['start_date']);
        $previousTo = CarbonImmutable::parse($period['to'])->subYear()->toDateString();
        $hasPrevious = $previousTo >= $period['start_date'];
        $current = $this->balances($tenantId, $scope, $period['from'], $period['to']);
        $previous = $hasPrevious ? $this->balances($tenantId, $scope, $previousFrom, $previousTo) : null;
        $releasedCurrent = $this->released($tenantId, $scope, $period['from'], $period['to']);
        $releasedPrevious = $hasPrevious ? $this->released($tenantId, $scope, $previousFrom, $previousTo) : null;

        $freeRevenue = $this->sections($accounts, AccountGroup::Revenue, $current, $previous, -1, false);
        $restrictedRevenue = $this->sections($accounts, AccountGroup::Revenue, $current, $previous, -1, true);
        $expenses = $this->sections($accounts, AccountGroup::Expense, $current, $previous, 1);

        $freeTotal = $this->total($freeRevenue, $hasPrevious);
        $expenseTotal = $this->total($expenses, $hasPrevious);
        $restrictedTotal = $this->total($restrictedRevenue, $hasPrevious);
        $surplus = [
            'current' => $freeTotal['current'] + $releasedCurrent - $expenseTotal['current'],
            'previous' => $hasPrevious ? (int) $freeTotal['previous'] + (int) $releasedPrevious - (int) $expenseTotal['previous'] : null,
        ];
        $change = [
            'current' => $restrictedTotal['current'] - $releasedCurrent,
            'previous' => $hasPrevious ? (int) $restrictedTotal['previous'] - (int) $releasedPrevious : null,
        ];

        return [
            'from' => $period['from'],
            'to' => $period['to'],
            'previous_from' => $hasPrevious ? $previousFrom : null,
            'previous_to' => $hasPrevious ? $previousTo : null,
            'unrestricted' => [
                'revenue' => $freeRevenue,
                'released' => ['current' => $releasedCurrent, 'previous' => $releasedPrevious],
                'revenue_total' => [
                    'current' => $freeTotal['current'] + $releasedCurrent,
                    'previous' => $hasPrevious ? (int) $freeTotal['previous'] + (int) $releasedPrevious : null,
                ],
                'expenses' => $expenses,
                'expense_total' => $expenseTotal,
                'surplus' => $surplus,
            ],
            'restricted' => [
                'revenue' => $restrictedRevenue,
                'released' => ['current' => -$releasedCurrent, 'previous' => $releasedPrevious === null ? null : -$releasedPrevious],
                'change' => $change,
            ],
            'total' => [
                'current' => $surplus['current'] + $change['current'],
                'previous' => $hasPrevious ? (int) $surplus['previous'] + (int) $change['previous'] : null,
            ],
        ];
    }

    /**
     * Perubahan aset neto periode, per golongan: saldo awal, surplus/perubahan dari laporan penghasilan komprehensif,
     * penyesuaian langsung lain, saldo akhir.
     *
     * @param  Period  $period
     * @return Changes
     */
    public function netAssetChanges(string $tenantId, ?string $scope, array $period): array
    {
        $accounts = $this->accountMap($tenantId);
        $openingDate = CarbonImmutable::parse($period['from'])->subDay()->toDateString();
        $opening = $this->netAssets($accounts, $this->balances($tenantId, $scope, null, $openingDate));
        $closing = $this->netAssets($accounts, $this->balances($tenantId, $scope, null, $period['to']));
        $activities = $this->activities($tenantId, $scope, $period);
        $rows = [];

        foreach (['unrestricted' => (int) $activities['unrestricted']['surplus']['current'], 'restricted' => (int) $activities['restricted']['change']['current']] as $class => $change) {
            $rows[$class] = [
                'opening' => $opening[$class],
                'change' => $change,
                'other' => $closing[$class] - $opening[$class] - $change,
                'closing' => $closing[$class],
            ];
        }

        $rows['total'] = [
            'opening' => $rows['unrestricted']['opening'] + $rows['restricted']['opening'],
            'change' => $rows['unrestricted']['change'] + $rows['restricted']['change'],
            'other' => $rows['unrestricted']['other'] + $rows['restricted']['other'],
            'closing' => $rows['unrestricted']['closing'] + $rows['restricted']['closing'],
        ];

        return $rows;
    }

    /**
     * Arus kas metode langsung.
     *
     * @param  Period  $period
     * @return CashFlows
     */
    public function cashFlows(string $tenantId, ?string $scope, array $period): array
    {
        $keyed = $this->accounts->keyed($tenantId);
        $custodyId = $keyed[ChartOfAccounts::KEY_SAVINGS_CASH] ?? '';
        $cashIds = [];

        foreach (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->where('is_cash', true)->where('id', '!=', $custodyId)->pluck('id') as $id) {
            $cashIds[] = (string) $id;
        }

        $openingDate = CarbonImmutable::parse($period['from'])->subDay()->toDateString();
        $cashBalance = fn (string $date, array $ids): int => $ids === [] ? 0 : (int) LedgerQuery::scoped(LedgerQuery::validLines($tenantId), $scope)
            ->whereIn('l.account_id', $ids)
            ->whereRaw(LedgerQuery::EFFECTIVE_DATE.' <= ?', [$date])
            ->sum(DB::raw('l.debit - l.credit'));

        $inPeriod = fn (Builder $query): Builder => $query->whereRaw(LedgerQuery::EFFECTIVE_DATE.' BETWEEN ? AND ?', [$period['from'], $period['to']]);

        // Jurnal yang menggerakkan kas dalam lingkup (selain saldo awal rekening baru).
        $cashJournals = $inPeriod(LedgerQuery::scoped(LedgerQuery::validLines($tenantId), $scope))
            ->whereIn('l.account_id', $cashIds === [] ? [''] : $cashIds)
            ->whereNotIn('j.source', [JournalSource::AccountOpening->value, JournalSource::Opening->value])
            ->groupBy('l.journal_id')
            ->havingRaw('SUM(l.debit - l.credit) <> 0')
            ->select('l.journal_id');

        $byActivity = [];

        foreach ($cashIds === [] ? [] : LedgerQuery::scoped(LedgerQuery::validLines($tenantId), $scope)
            ->join('fn_ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.journal_id', $cashJournals)
            ->whereNotIn('l.account_id', $cashIds)
            ->groupBy('a.code', 'a.name', 'a.cash_flow_activity')
            ->orderBy('a.code')
            ->get(['a.code', 'a.name', 'a.cash_flow_activity', DB::raw('SUM(l.credit) AS receipts'), DB::raw('SUM(l.debit) AS payments')]) as $row) {
            $activity = CashFlowActivity::tryFrom((string) $row->cash_flow_activity) ?? CashFlowActivity::Operating;
            $byActivity[$activity->value][] = $row;
        }

        $activities = [];
        $netChange = 0;

        foreach (CashFlowActivity::cases() as $activity) {
            $receipts = [];
            $payments = [];
            $net = 0;

            foreach ($byActivity[$activity->value] ?? [] as $row) {
                if ((int) $row->receipts > 0) {
                    $receipts[] = ['code' => (string) $row->code, 'name' => (string) $row->name, 'amount' => (int) $row->receipts];
                }

                if ((int) $row->payments > 0) {
                    $payments[] = ['code' => (string) $row->code, 'name' => (string) $row->name, 'amount' => (int) $row->payments];
                }

                $net += (int) $row->receipts - (int) $row->payments;
            }

            $activities[] = ['activity' => $activity->value, 'label' => 'Arus kas dari aktivitas '.mb_strtolower($activity->label()), 'receipts' => $receipts, 'payments' => $payments, 'net' => $net];
            $netChange += $net;
        }

        $newAccounts = $cashIds === [] ? 0 : (int) $inPeriod(LedgerQuery::scoped(LedgerQuery::validLines($tenantId), $scope))
            ->whereIn('l.account_id', $cashIds)
            ->where('j.source', JournalSource::AccountOpening->value)
            ->sum(DB::raw('l.debit - l.credit'));

        return [
            'activities' => $activities,
            'net_change' => $netChange,
            'new_accounts' => $newAccounts,
            'opening' => $cashBalance($openingDate, $cashIds),
            'closing' => $cashBalance($period['to'], $cashIds),
            'custody' => [
                'opening' => $custodyId === '' ? 0 : $cashBalance($openingDate, [$custodyId]),
                'closing' => $custodyId === '' ? 0 : $cashBalance($period['to'], [$custodyId]),
            ],
        ];
    }

    /**
     * Saldo bertanda (+ = debit) per akun, tanggal efektif dalam [$from, $to] ($from null = sejak awal).
     *
     * @return array<string, int>
     */
    private function balances(string $tenantId, ?string $scope, ?string $from, string $to): array
    {
        $totals = [];

        foreach (LedgerQuery::scoped(LedgerQuery::validLines($tenantId), $scope)
            ->when($from !== null, fn (Builder $query) => $query->whereRaw(LedgerQuery::EFFECTIVE_DATE.' >= ?', [$from]))
            ->whereRaw(LedgerQuery::EFFECTIVE_DATE.' <= ?', [$to])
            ->groupBy('l.account_id')
            ->get(['l.account_id', DB::raw('SUM(l.debit - l.credit) AS total')]) as $row) {
            $totals[(string) $row->account_id] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * Aset neto yang dibebaskan dari pembatasan dalam periode: kredit bersih ke akun aset neto tanpa pembatasan pada
     * jurnal yang juga mendebit akun aset neto dengan pembatasan.
     */
    private function released(string $tenantId, ?string $scope, string $from, string $to): int
    {
        $restrictedJournals = LedgerQuery::validLines($tenantId)
            ->join('fn_ledger_accounts as ra', 'ra.id', '=', 'l.account_id')
            ->where('ra.account_group', AccountGroup::NetAssets->value)
            ->where('ra.is_restricted', true)
            ->where('l.debit', '>', 0)
            ->whereRaw(LedgerQuery::EFFECTIVE_DATE.' BETWEEN ? AND ?', [$from, $to])
            ->select('l.journal_id');

        return (int) LedgerQuery::scoped(LedgerQuery::validLines($tenantId), $scope)
            ->join('fn_ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.account_group', AccountGroup::NetAssets->value)
            ->where('a.is_restricted', false)
            ->whereIn('l.journal_id', $restrictedJournals)
            ->sum(DB::raw('l.credit - l.debit'));
    }

    /**
     * @param  array<string, stdClass>  $accounts
     * @param  array<string, int>  $balances
     * @return array{unrestricted: int, restricted: int}
     */
    private function netAssets(array $accounts, array $balances): array
    {
        $result = ['unrestricted' => 0, 'restricted' => 0];

        foreach ($balances as $id => $balance) {
            $account = $accounts[$id] ?? null;

            if ($account === null || ! in_array((string) $account->account_group, [AccountGroup::NetAssets->value, AccountGroup::Revenue->value, AccountGroup::Expense->value], true)) {
                continue;
            }

            // Aset neto bersaldo normal kredit: saldo bertanda (+ = debit) dibalik.
            $result[(bool) $account->is_restricted ? 'restricted' : 'unrestricted'] -= $balance;
        }

        return $result;
    }

    /**
     * Kelompok akun per akun judul induk. $sign: 1 = tampil sebagai debit − kredit, −1 = kredit − debit.
     * $restricted: null = semua, true/false = hanya akun bertanda / tidak bertanda "dengan pembatasan".
     *
     * @param  array<string, stdClass>  $accounts
     * @param  array<string, int>  $current
     * @param  array<string, int>|null  $previous
     * @return list<Section>
     */
    private function sections(array $accounts, AccountGroup $group, array $current, ?array $previous, int $sign, ?bool $restricted = null): array
    {
        $sections = [];

        foreach ($accounts as $id => $account) {
            if ((string) $account->account_group !== $group->value || (bool) $account->is_header) {
                continue;
            }

            if ($restricted !== null && (bool) $account->is_restricted !== $restricted) {
                continue;
            }

            $now = $sign * ($current[$id] ?? 0);
            $before = $previous === null ? null : $sign * ($previous[$id] ?? 0);

            if ($now === 0 && ($before ?? 0) === 0) {
                continue;
            }

            $parent = $account->parent_id === null ? null : ($accounts[(string) $account->parent_id] ?? null);
            $key = $parent === null ? '~' : (string) $parent->code;
            $sections[$key] ??= ['title' => $parent === null ? 'Lainnya' : (string) $parent->name, 'lines' => [], 'current' => 0, 'previous' => $previous === null ? null : 0];
            $sections[$key]['lines'][] = ['code' => (string) $account->code, 'name' => (string) $account->name, 'current' => $now, 'previous' => $before];
            $sections[$key]['current'] += $now;

            if ($before !== null) {
                $sections[$key]['previous'] = (int) $sections[$key]['previous'] + $before;
            }
        }

        ksort($sections);

        return array_values($sections);
    }

    /**
     * @param  list<Section>  $sections
     * @return array{current: int, previous: int|null}
     */
    private function total(array $sections, bool $hasPrevious): array
    {
        $current = 0;
        $previous = 0;

        foreach ($sections as $section) {
            $current += $section['current'];
            $previous += (int) $section['previous'];
        }

        return ['current' => $current, 'previous' => $hasPrevious ? $previous : null];
    }

    /**
     * @return array<string, stdClass>
     */
    private function accountMap(string $tenantId): array
    {
        $map = [];

        foreach (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->orderBy('code')->get(['id', 'code', 'name', 'account_group', 'is_header', 'parent_id', 'is_restricted']) as $account) {
            $map[(string) $account->id] = $account;
        }

        return $map;
    }
}
