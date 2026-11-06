<?php

namespace Modules\Finance\Application\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Cash\CashBalances;
use Modules\Finance\Domain\Invoice\InvoiceStatus;
use Modules\Finance\Domain\Ledger\AccountGroup;
use Modules\Finance\Domain\Ledger\JournalSource;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Buku besar, neraca saldo, cek kecocokan (PRD-007 §7, PB-2b). Hanya jurnal SAH. Hak akses & lingkup dicek pemanggil.
 *
 * Lingkup: null = gabungan; '' = Yayasan pusat; id = satu lembaga (baris jurnal berlembaga itu).
 *
 * Saldo awal periode [ASUMSI PB-2b]: akun neraca (aset, liabilitas, aset neto) = semua mutasi sebelum tanggal awal;
 * akun pendapatan & beban mulai dari nol di awal tahun buku — hasil tahun-tahun buku sebelumnya dihitung masuk Aset neto
 * tanpa pembatasan (akun pendapatan bertanda "dengan pembatasan" → Aset neto dengan pembatasan), tanpa jurnal tutup buku
 * (PRD-007 §7). Jurnal saldo awal selalu dihitung sebagai saldo awal (bukan mutasi). Rentang dibatasi satu tahun buku.
 *
 * @phpstan-type Period array{from: string, to: string, fiscal_start: string, fiscal_end: string, start_date: string, clamped: bool}
 * @phpstan-type BalanceRow array{id: string, code: string, name: string, group: string, normal_balance: string, opening: int, debit: int, credit: int, closing: int}
 * @phpstan-type LedgerRow array{journal_id: string, date: string, number: string, description: string, source_label: string, organization: string, debit: int, credit: int, balance: int}
 * @phpstan-type CheckRow array{kind: string, label: string, organization: string, ledger: int, book: int, difference: int, href: string|null}
 */
final class LedgerReports
{
    public const PER_PAGE = 100;

    /**
     * Tanggal efektif baris jurnal: jurnal saldo awal (bertanggal tanggal mulai) dihitung sebagai saldo SEBELUM tanggal
     * mulai, supaya tampil sebagai saldo awal periode, bukan mutasi.
     */
    private const EFFECTIVE_DATE = "(j.journal_date - CASE WHEN j.source = 'SALDO_AWAL' THEN 1 ELSE 0 END)";

    public function __construct(
        private readonly ChartOfAccounts $accounts,
        private readonly CashBalances $balances,
    ) {}

    /**
     * Rentang laporan dari isian (kosong/tidak valid → awal tahun buku berjalan s.d. hari ini), dijepit ke tanggal mulai
     * pembukuan dan ke satu tahun buku. Null bila pembukuan belum aktif.
     *
     * @return Period|null
     */
    public function period(string $tenantId, mixed $from, mixed $to): ?array
    {
        $settings = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->first(['start_date', 'fiscal_start_month']);

        if ($settings === null || $settings->start_date === null) {
            return null;
        }

        $start = substr((string) $settings->start_date, 0, 10);
        $month = (int) $settings->fiscal_start_month;
        $today = BusinessClock::todayString();
        $from = $this->date($from) ?? max($this->fiscalStart($today, $month), $start);
        $from = max($from, $start);
        $fiscalStart = $this->fiscalStart($from, $month);
        $fiscalEnd = CarbonImmutable::parse($fiscalStart)->addYear()->subDay()->toDateString();
        $to = $this->date($to) ?? min(max($today, $from), $fiscalEnd);
        $to = max($to, $from);
        $clamped = $to > $fiscalEnd;

        return [
            'from' => $from,
            'to' => min($to, $fiscalEnd),
            'fiscal_start' => $fiscalStart,
            'fiscal_end' => $fiscalEnd,
            'start_date' => $start,
            'clamped' => $clamped,
        ];
    }

    /**
     * Pilihan cepat rentang: bulan ini, bulan lalu, tahun buku ini, tahun buku lalu (yang tidak sebelum tanggal mulai).
     *
     * @param  Period  $period
     * @return list<array{label: string, from: string, to: string}>
     */
    public function presets(array $period, int $fiscalMonth): array
    {
        $today = BusinessClock::today();
        $presets = [
            ['label' => 'Bulan ini', 'from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString()],
            ['label' => 'Bulan lalu', 'from' => $today->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ];
        $fiscalStart = CarbonImmutable::parse($this->fiscalStart($today->toDateString(), $fiscalMonth));
        $presets[] = ['label' => 'Tahun buku ini', 'from' => $fiscalStart->toDateString(), 'to' => $fiscalStart->addYear()->subDay()->toDateString()];
        $presets[] = ['label' => 'Tahun buku lalu', 'from' => $fiscalStart->subYear()->toDateString(), 'to' => $fiscalStart->subDay()->toDateString()];

        return array_values(array_filter($presets, fn (array $preset): bool => $preset['to'] >= $period['start_date']));
    }

    public function fiscalMonth(string $tenantId): int
    {
        return (int) (DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->value('fiscal_start_month') ?? 1);
    }

    /**
     * Neraca saldo: semua akun posting dengan saldo awal, mutasi debit/kredit, saldo akhir (bertanda: + = debit).
     *
     * @param  Period  $period
     * @return array{rows: list<BalanceRow>, totals: array{opening_debit: int, opening_credit: int, debit: int, credit: int, closing_debit: int, closing_credit: int}}
     */
    public function trialBalance(string $tenantId, ?string $scope, array $period): array
    {
        $sums = $this->sums($tenantId, $scope, $period);
        $rows = [];
        $totals = ['opening_debit' => 0, 'opening_credit' => 0, 'debit' => 0, 'credit' => 0, 'closing_debit' => 0, 'closing_credit' => 0];

        foreach (DB::table('fn_ledger_accounts')
            ->where('tenant_id', $tenantId)
            ->where('is_header', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_group', 'normal_balance']) as $account) {
            $id = (string) $account->id;
            $opening = $sums['opening'][$id] ?? 0;
            $debit = $sums['debit'][$id] ?? 0;
            $credit = $sums['credit'][$id] ?? 0;
            $closing = $opening + $debit - $credit;

            $rows[] = [
                'id' => $id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'group' => (string) $account->account_group,
                'normal_balance' => (string) $account->normal_balance,
                'opening' => $opening,
                'debit' => $debit,
                'credit' => $credit,
                'closing' => $closing,
            ];

            $totals['opening_debit'] += max(0, $opening);
            $totals['opening_credit'] += max(0, -$opening);
            $totals['debit'] += $debit;
            $totals['credit'] += $credit;
            $totals['closing_debit'] += max(0, $closing);
            $totals['closing_credit'] += max(0, -$closing);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Akun posting untuk pilihan buku besar.
     *
     * @return list<array{value: string, label: string}>
     */
    public function accountOptions(string $tenantId): array
    {
        $options = [];

        foreach (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->where('is_header', false)->orderBy('code')->get(['id', 'code', 'name', 'is_active']) as $account) {
            $options[] = ['value' => (string) $account->id, 'label' => "{$account->code} · {$account->name}".((bool) $account->is_active ? '' : ' (nonaktif)')];
        }

        return $options;
    }

    /**
     * Buku besar satu akun: saldo awal, baris mutasi (100 per halaman) dengan saldo berjalan, saldo akhir.
     *
     * @param  Period  $period
     * @return array{account: array{id: string, code: string, name: string, normal_balance: string}, opening: int, debit: int, credit: int, closing: int, rows: list<LedgerRow>, total: int, page: int, last_page: int}|null
     */
    public function ledger(string $tenantId, string $accountId, ?string $scope, array $period, int $page): ?array
    {
        $account = Str::isUuid($accountId)
            ? DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->where('is_header', false)->first(['id', 'code', 'name', 'normal_balance'])
            : null;

        if ($account === null) {
            return null;
        }

        $sums = $this->sums($tenantId, $scope, $period, $accountId);
        $opening = $sums['opening'][$accountId] ?? 0;
        $debit = $sums['debit'][$accountId] ?? 0;
        $credit = $sums['credit'][$accountId] ?? 0;
        $total = $this->rangeLines($tenantId, $accountId, $scope, $period)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        return [
            'account' => ['id' => (string) $account->id, 'code' => (string) $account->code, 'name' => (string) $account->name, 'normal_balance' => (string) $account->normal_balance],
            'opening' => $opening,
            'debit' => $debit,
            'credit' => $credit,
            'closing' => $opening + $debit - $credit,
            'rows' => $this->ledgerRows($tenantId, $accountId, $scope, $period, $opening, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Baris buku besar dengan saldo berjalan (+ = debit), mulai dari $offset.
     *
     * @param  Period  $period
     * @return list<LedgerRow>
     */
    public function ledgerRows(string $tenantId, string $accountId, ?string $scope, array $period, int $opening, int $offset = 0, ?int $limit = null): array
    {
        $running = DB::query()
            ->fromSub(
                $this->rangeLines($tenantId, $accountId, $scope, $period)
                    ->leftJoin('organizations as o', 'o.id', '=', 'l.organization_id')
                    ->select([
                        'j.id as journal_id', 'j.journal_date', 'j.number', 'j.description', 'j.source', 'o.name as organization', 'l.debit', 'l.credit',
                        DB::raw('SUM(l.debit - l.credit) OVER (ORDER BY j.journal_date, j.number, l.line_no ROWS UNBOUNDED PRECEDING) AS running'),
                        DB::raw('ROW_NUMBER() OVER (ORDER BY j.journal_date, j.number, l.line_no) AS position'),
                    ]),
                'r',
            )
            ->orderBy('position')
            ->offset($offset)
            ->when($limit !== null, fn (Builder $query) => $query->limit((int) $limit));
        $rows = [];

        foreach ($running->get() as $row) {
            $rows[] = [
                'journal_id' => (string) $row->journal_id,
                'date' => substr((string) $row->journal_date, 0, 10),
                'number' => (string) $row->number,
                'description' => (string) $row->description,
                'source_label' => JournalSource::from((string) $row->source)->label(),
                'organization' => $row->organization === null ? 'Yayasan' : (string) $row->organization,
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
                'balance' => $opening + (int) $row->running,
            ];
        }

        return $rows;
    }

    /**
     * Cek kecocokan pembukuan dengan buku pembantu (PRD-007 §7), semua tanggal: saldo akun per rekening kas vs buku kas;
     * Piutang siswa vs sisa tagihan; Utang titipan vs saldo tabungan siswa; RAK gabungan = 0. Baris yang dua sisinya nol
     * tidak ditampilkan.
     *
     * @param  list<string>|null  $organizationIds  null = semua lembaga + Kas Yayasan + RAK; selain itu lembaga ini saja
     * @return list<CheckRow>
     */
    public function reconciliation(string $tenantId, ?array $organizationIds): array
    {
        $keyed = $this->accounts->keyed($tenantId);
        $names = DB::table('organizations')->where('tenant_id', $tenantId)->pluck('name', 'id');
        $label = fn (mixed $organizationId): string => $organizationId === null ? 'Kas Yayasan' : (string) ($names[(string) $organizationId] ?? '');
        $rows = [];

        // Kas per rekening.
        $cashAccounts = DB::table('fn_cash_accounts')
            ->where('tenant_id', $tenantId)
            ->when($organizationIds !== null, fn (Builder $query) => $query->whereIn('organization_id', $organizationIds))
            ->orderByRaw('organization_id IS NOT NULL')
            ->orderBy('name')
            ->get(['id', 'organization_id', 'name']);
        $ids = [];

        foreach ($cashAccounts as $account) {
            $ids[] = (string) $account->id;
        }

        $books = $ids === [] ? [] : $this->balances->balances($ids);
        $ledger = [];

        if ($ids !== []) {
            foreach ($this->validLines($tenantId)
                ->whereIn('l.cash_account_id', $ids)
                ->groupBy('l.cash_account_id')
                ->get(['l.cash_account_id', DB::raw('SUM(l.debit - l.credit) AS total')]) as $row) {
                $ledger[(string) $row->cash_account_id] = (int) $row->total;
            }
        }

        foreach ($cashAccounts as $account) {
            $id = (string) $account->id;
            $rows[] = $this->check('KAS', (string) $account->name, $label($account->organization_id), $ledger[$id] ?? 0, $books[$id] ?? 0,
                '/keuangan/kas?'.http_build_query(['kas' => $account->organization_id === null ? 'yayasan' : (string) $account->organization_id]));
        }

        // Piutang & titipan per lembaga.
        $receivable = $this->byOrganization($tenantId, $keyed[ChartOfAccounts::KEY_RECEIVABLE] ?? '', $organizationIds);
        $custody = $this->byOrganization($tenantId, $keyed[ChartOfAccounts::KEY_SAVINGS_LIABILITY] ?? '', $organizationIds);
        $arrears = [];

        foreach (DB::table('fn_invoices')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->when($organizationIds !== null, fn (Builder $query) => $query->whereIn('organization_id', $organizationIds))
            ->groupBy('organization_id')
            ->get(['organization_id', DB::raw('SUM(amount - paid_amount) AS total')]) as $row) {
            $arrears[(string) $row->organization_id] = (int) $row->total;
        }

        $savings = [];

        foreach (DB::table('fn_savings_accounts')
            ->where('tenant_id', $tenantId)
            ->when($organizationIds !== null, fn (Builder $query) => $query->whereIn('organization_id', $organizationIds))
            ->groupBy('organization_id')
            ->get(['organization_id', DB::raw('SUM(balance) AS total')]) as $row) {
            $savings[(string) $row->organization_id] = (int) $row->total;
        }

        $organizations = array_unique([...array_keys($receivable), ...array_keys($arrears), ...array_keys($custody), ...array_keys($savings)]);
        usort($organizations, fn (string $a, string $b): int => strcmp($label($a), $label($b)));

        foreach ($organizations as $organizationId) {
            $rows[] = $this->check('PIUTANG', 'Piutang siswa vs sisa tagihan', $label($organizationId), $receivable[$organizationId] ?? 0, $arrears[$organizationId] ?? 0,
                '/keuangan/tunggakan?'.http_build_query(['lembaga' => $organizationId]));
            // Utang titipan bersaldo normal kredit → dibalik supaya sebanding dengan saldo tabungan.
            $rows[] = $this->check('TITIPAN', 'Utang titipan vs saldo tabungan', $label($organizationId), -($custody[$organizationId] ?? 0), $savings[$organizationId] ?? 0,
                '/keuangan/tabungan?'.http_build_query(['lembaga' => $organizationId]));
        }

        if ($organizationIds === null && isset($keyed[ChartOfAccounts::KEY_INTER_OFFICE])) {
            $rak = (int) $this->validLines($tenantId)->where('l.account_id', $keyed[ChartOfAccounts::KEY_INTER_OFFICE])->sum(DB::raw('l.debit - l.credit'));
            $rows[] = $this->check('RAK', 'Rekening antar-kantor gabungan (harus 0)', 'Seluruh yayasan', $rak, 0, null);
        }

        return array_values(array_filter($rows, fn (array $row): bool => $row['ledger'] !== 0 || $row['book'] !== 0 || $row['kind'] === 'RAK'));
    }

    /**
     * @return CheckRow
     */
    private function check(string $kind, string $label, string $organization, int $ledger, int $book, ?string $href): array
    {
        return ['kind' => $kind, 'label' => $label, 'organization' => $organization, 'ledger' => $ledger, 'book' => $book, 'difference' => $ledger - $book, 'href' => $href];
    }

    /**
     * Saldo (debit − kredit) satu akun per lembaga, semua tanggal.
     *
     * @param  list<string>|null  $organizationIds
     * @return array<string, int>
     */
    private function byOrganization(string $tenantId, string $accountId, ?array $organizationIds): array
    {
        if ($accountId === '') {
            return [];
        }

        $totals = [];

        foreach ($this->validLines($tenantId)
            ->where('l.account_id', $accountId)
            ->whereNotNull('l.organization_id')
            ->when($organizationIds !== null, fn (Builder $query) => $query->whereIn('l.organization_id', $organizationIds))
            ->groupBy('l.organization_id')
            ->get(['l.organization_id', DB::raw('SUM(l.debit - l.credit) AS total')]) as $row) {
            $totals[(string) $row->organization_id] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * Saldo awal (bertanda) + mutasi debit/kredit periode per akun.
     *
     * @param  Period  $period
     * @return array{opening: array<string, int>, debit: array<string, int>, credit: array<string, int>}
     */
    private function sums(string $tenantId, ?string $scope, array $period, ?string $accountId = null): array
    {
        $keyed = $this->accounts->keyed($tenantId);
        $free = $keyed[ChartOfAccounts::KEY_NET_ASSETS_FREE] ?? null;
        $restricted = $keyed[ChartOfAccounts::KEY_NET_ASSETS_RESTRICTED] ?? null;
        $result = ['opening' => [], 'debit' => [], 'credit' => []];
        $rolled = ['free' => 0, 'restricted' => 0];

        $query = $this->scoped($this->validLines($tenantId), $scope)
            ->join('fn_ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereRaw(self::EFFECTIVE_DATE.' <= ?', [$period['to']])
            ->groupBy('l.account_id', 'a.account_group', 'a.is_restricted')
            ->select(['l.account_id', 'a.account_group', 'a.is_restricted'])
            ->selectRaw('SUM(CASE WHEN '.self::EFFECTIVE_DATE.' < ? THEN l.debit - l.credit ELSE 0 END) AS before_from', [$period['from']])
            ->selectRaw('SUM(CASE WHEN '.self::EFFECTIVE_DATE.' < ? THEN l.debit - l.credit ELSE 0 END) AS before_fiscal', [$period['fiscal_start']])
            ->selectRaw('SUM(CASE WHEN '.self::EFFECTIVE_DATE.' >= ? THEN l.debit ELSE 0 END) AS period_debit', [$period['from']])
            ->selectRaw('SUM(CASE WHEN '.self::EFFECTIVE_DATE.' >= ? THEN l.credit ELSE 0 END) AS period_credit', [$period['from']]);

        foreach ($query->get() as $row) {
            $id = (string) $row->account_id;
            $profitLoss = in_array((string) $row->account_group, [AccountGroup::Revenue->value, AccountGroup::Expense->value], true);
            $beforeFrom = (int) $row->before_from;
            $beforeFiscal = (int) $row->before_fiscal;

            if ($profitLoss) {
                $result['opening'][$id] = $beforeFrom - $beforeFiscal;
                $rolled[(bool) $row->is_restricted ? 'restricted' : 'free'] += $beforeFiscal;
            } else {
                $result['opening'][$id] = $beforeFrom;
            }

            $result['debit'][$id] = (int) $row->period_debit;
            $result['credit'][$id] = (int) $row->period_credit;
        }

        // Surplus/defisit tahun buku sebelumnya → aset neto (tanpa jurnal tutup buku).
        foreach ([[$free, 'free'], [$restricted, 'restricted']] as [$target, $key]) {
            if ($target !== null && $rolled[$key] !== 0) {
                $result['opening'][$target] = ($result['opening'][$target] ?? 0) + $rolled[$key];
            }
        }

        if ($accountId !== null) {
            foreach ($result as $kind => $values) {
                $result[$kind] = isset($values[$accountId]) ? [$accountId => $values[$accountId]] : [];
            }
        }

        return $result;
    }

    /**
     * @param  Period  $period
     */
    private function rangeLines(string $tenantId, string $accountId, ?string $scope, array $period): Builder
    {
        return $this->scoped($this->validLines($tenantId), $scope)
            ->where('l.account_id', $accountId)
            ->whereRaw(self::EFFECTIVE_DATE.' BETWEEN ? AND ?', [$period['from'], $period['to']]);
    }

    private function validLines(string $tenantId): Builder
    {
        return DB::table('fn_journal_lines as l')
            ->join('fn_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.tenant_id', $tenantId)
            ->where('j.status', PaymentStatus::Valid->value);
    }

    private function scoped(Builder $query, ?string $scope): Builder
    {
        return match ($scope) {
            null => $query,
            '' => $query->whereNull('l.organization_id'),
            default => $query->where('l.organization_id', $scope),
        };
    }

    private function fiscalStart(string $date, int $month): string
    {
        $day = CarbonImmutable::parse($date);
        $year = $day->month >= $month ? $day->year : $day->year - 1;

        return sprintf('%04d-%02d-01', $year, $month);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
    }
}
