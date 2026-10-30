<?php

namespace Modules\Finance\Application\Report;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Laporan arus kas (PRD-004 §6, KS-4) untuk sekumpulan kas dan satu periode (bulan `Y-m` atau tahun `Y`).
 * Hak akses dicek pemanggil.
 *
 * - Hanya transaksi SAH. Pemasukan & pengeluaran per kategori; mutasi (KS-4) dihitung terpisah.
 * - Saldo awal periode = saldo awal rekening yang sudah mulai dipakai sampai akhir periode + transaksi sebelum periode.
 *   Saldo akhir = saldo awal + pemasukan − pengeluaran + mutasi masuk − mutasi keluar.
 *
 * @phpstan-type BookSummary array{organization_id: string|null, opening: int, income: int, expense: int, transfer_in: int, transfer_out: int, closing: int}
 * @phpstan-type Report array{from: string, to: string, opening: int, closing: int, income: list<array{category: string, amount: int}>, income_total: int, expense: list<array{category: string, amount: int}>, expense_total: int, transfer_in: int, transfer_out: int, months: list<array{month: string, income: int, expense: int}>, books: list<BookSummary>}
 * @phpstan-type Transaction array{date: string, organization_id: string|null, account: string, category: string, description: string, direction: string, amount: int, source: string}
 */
final class CashFlowReport
{
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /**
     * Pilihan periode: tahun ini & tahun lalu, lalu bulan ini + 24 bulan ke belakang.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function periodOptions(CarbonImmutable $today): array
    {
        $options = [
            ['value' => (string) $today->year, 'label' => 'Tahun '.$today->year],
            ['value' => (string) ($today->year - 1), 'label' => 'Tahun '.($today->year - 1)],
        ];

        foreach (ReceiptRecap::monthOptions($today) as $month) {
            $options[] = $month;
        }

        return $options;
    }

    /**
     * @return array{0: string, 1: string} tanggal awal & akhir periode
     */
    public static function range(string $period): array
    {
        if (strlen($period) === 4) {
            return [$period.'-01-01', $period.'-12-31'];
        }

        $first = CarbonImmutable::parse($period.'-01');

        return [$first->toDateString(), $first->endOfMonth()->toDateString()];
    }

    /**
     * @param  list<string>  $organizationIds
     * @return Report
     */
    public function build(string $tenantId, array $organizationIds, bool $includeYayasan, string $period): array
    {
        [$from, $to] = self::range($period);

        // Per kas: kunci '' = kas Yayasan. Tiap besaran dikumpulkan di peta tersendiri (kas → rupiah).
        $organizations = [];
        $sums = ['opening' => [], 'income' => [], 'expense' => [], 'transfer_in' => [], 'transfer_out' => []];
        $add = function (string $field, mixed $organizationId, int $amount) use (&$organizations, &$sums): void {
            $key = $organizationId === null ? '' : (string) $organizationId;
            $organizations[$key] = $organizationId === null ? null : (string) $organizationId;
            $sums[$field][$key] = ($sums[$field][$key] ?? 0) + $amount;
        };

        foreach ($this->scope(DB::table('fn_cash_accounts'), $tenantId, $organizationIds, $includeYayasan, 'organization_id')
            ->where('opening_date', '<=', $to)
            ->groupBy('organization_id')
            ->get(['organization_id', DB::raw('SUM(opening_balance) AS total')]) as $row) {
            $add('opening', $row->organization_id, (int) $row->total);
        }

        foreach ($this->entries($tenantId, $organizationIds, $includeYayasan)
            ->where('e.entry_date', '<', $from)
            ->groupBy('e.organization_id', 'e.direction')
            ->get(['e.organization_id', 'e.direction', DB::raw('SUM(e.amount) AS total')]) as $row) {
            $add('opening', $row->organization_id, ((string) $row->direction === CashDirection::In->value ? 1 : -1) * (int) $row->total);
        }

        $income = [];
        $expense = [];
        $months = [];

        foreach ($this->entries($tenantId, $organizationIds, $includeYayasan)
            ->whereBetween('e.entry_date', [$from, $to])
            ->groupBy('e.organization_id', 'e.direction', 'e.source', 'c.name', DB::raw("to_char(e.entry_date, 'YYYY-MM')"))
            ->get(['e.organization_id', 'e.direction', 'e.source', 'c.name as category', DB::raw("to_char(e.entry_date, 'YYYY-MM') AS month"), DB::raw('SUM(e.amount) AS total')]) as $row) {
            $amount = (int) $row->total;
            $incoming = (string) $row->direction === CashDirection::In->value;
            $month = (string) $row->month;

            if ((string) $row->source === CashEntrySource::Transfer->value) {
                $add($incoming ? 'transfer_in' : 'transfer_out', $row->organization_id, $amount);

                continue;
            }

            $add($incoming ? 'income' : 'expense', $row->organization_id, $amount);
            $months[$month] ??= ['income' => 0, 'expense' => 0];
            $months[$month][$incoming ? 'income' : 'expense'] += $amount;

            if ($incoming) {
                $income[(string) $row->category] = ($income[(string) $row->category] ?? 0) + $amount;
            } else {
                $expense[(string) $row->category] = ($expense[(string) $row->category] ?? 0) + $amount;
            }
        }

        $total = fn (string $field): int => array_sum($sums[$field]);
        $bookRows = [];

        foreach ($organizations as $key => $organizationId) {
            $row = [
                'organization_id' => $organizationId,
                'opening' => $sums['opening'][$key] ?? 0,
                'income' => $sums['income'][$key] ?? 0,
                'expense' => $sums['expense'][$key] ?? 0,
                'transfer_in' => $sums['transfer_in'][$key] ?? 0,
                'transfer_out' => $sums['transfer_out'][$key] ?? 0,
            ];
            $bookRows[] = $row + ['closing' => $row['opening'] + $row['income'] - $row['expense'] + $row['transfer_in'] - $row['transfer_out']];
        }

        $totals = [
            'opening' => $total('opening'),
            'income' => $total('income'),
            'expense' => $total('expense'),
            'transfer_in' => $total('transfer_in'),
            'transfer_out' => $total('transfer_out'),
        ];

        $monthRows = [];

        if (strlen($period) === 4) {
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%s-%02d', $period, $m);
                $monthRows[] = ['month' => self::MONTHS[$m].' '.$period, 'income' => $months[$key]['income'] ?? 0, 'expense' => $months[$key]['expense'] ?? 0];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'opening' => $totals['opening'],
            'closing' => $totals['opening'] + $totals['income'] - $totals['expense'] + $totals['transfer_in'] - $totals['transfer_out'],
            'income' => self::sorted($income),
            'income_total' => $totals['income'],
            'expense' => self::sorted($expense),
            'expense_total' => $totals['expense'],
            'transfer_in' => $totals['transfer_in'],
            'transfer_out' => $totals['transfer_out'],
            'months' => $monthRows,
            'books' => $bookRows,
        ];
    }

    /**
     * Transaksi sah dalam periode (untuk unduhan), urut tanggal.
     *
     * @param  list<string>  $organizationIds
     * @return list<Transaction>
     */
    public function transactions(string $tenantId, array $organizationIds, bool $includeYayasan, string $period): array
    {
        [$from, $to] = self::range($period);
        $rows = [];

        foreach ($this->entries($tenantId, $organizationIds, $includeYayasan)
            ->join('fn_cash_accounts as a', 'a.id', '=', 'e.account_id')
            ->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')
            ->orderBy('e.created_at')
            ->orderBy('e.id')
            ->get(['e.entry_date', 'e.organization_id', 'a.name as account', 'c.name as category', 'e.description', 'e.direction', 'e.amount', 'e.source']) as $row) {
            $rows[] = [
                'date' => substr((string) $row->entry_date, 0, 10),
                'organization_id' => $row->organization_id === null ? null : (string) $row->organization_id,
                'account' => (string) $row->account,
                'category' => (string) $row->category,
                'description' => (string) $row->description,
                'direction' => (string) $row->direction,
                'amount' => (int) $row->amount,
                'source' => (string) $row->source,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $organizationIds
     */
    private function entries(string $tenantId, array $organizationIds, bool $includeYayasan): Builder
    {
        return $this->scope(DB::table('fn_cash_entries as e'), $tenantId, $organizationIds, $includeYayasan, 'e.organization_id', 'e.tenant_id')
            ->join('fn_cash_categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.status', PaymentStatus::Valid->value);
    }

    /**
     * @param  list<string>  $organizationIds
     */
    private function scope(Builder $query, string $tenantId, array $organizationIds, bool $includeYayasan, string $column, string $tenantColumn = 'tenant_id'): Builder
    {
        return $query
            ->where($tenantColumn, $tenantId)
            ->where(function (Builder $where) use ($organizationIds, $includeYayasan, $column): void {
                $where->whereIn($column, $organizationIds);

                if ($includeYayasan) {
                    $where->orWhereNull($column);
                }
            });
    }

    /**
     * @param  array<string, int>  $totals
     * @return list<array{category: string, amount: int}>
     */
    private static function sorted(array $totals): array
    {
        arsort($totals);
        $rows = [];

        foreach ($totals as $category => $amount) {
            $rows[] = ['category' => (string) $category, 'amount' => $amount];
        }

        return $rows;
    }
}
