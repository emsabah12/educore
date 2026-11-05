<?php

namespace Modules\Finance\Application\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Ledger\JournalSource;

/**
 * Daftar & rincian jurnal (PRD-007 §7 "Daftar jurnal", §9; PB-1). Hanya baca; hak akses & lingkup dicek pemanggil.
 *
 * Lingkup: null = gabungan seluruh yayasan; '' = Yayasan pusat (baris tanpa lembaga); id = satu lembaga. Jurnal masuk
 * lingkup bila salah satu barisnya ada di lingkup itu.
 *
 * @phpstan-type JournalRow array{id: string, number: string, date: string, description: string, source: string, source_label: string, amount: int, status: string}
 * @phpstan-type JournalLineRow array{account_code: string, account_name: string, organization: string, cash_account: string|null, debit: int, credit: int}
 */
final class JournalList
{
    public const PER_PAGE = 50;

    public const SEARCH_MAX_LENGTH = 30;

    /**
     * @return array{rows: list<JournalRow>, total: int, page: int, last_page: int}
     */
    public function page(string $tenantId, ?string $scope, ?string $month, ?JournalSource $source, ?string $status, string $search, int $page): array
    {
        $query = $this->filtered($tenantId, $scope, $month, $source, $status, $search);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $rows = [];

        foreach ($query
            ->orderByDesc('j.journal_date')
            ->orderByDesc('j.number')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get(['j.id', 'j.number', 'j.journal_date', 'j.description', 'j.source', 'j.amount', 'j.status']) as $row) {
            $rows[] = [
                'id' => (string) $row->id,
                'number' => (string) $row->number,
                'date' => substr((string) $row->journal_date, 0, 10),
                'description' => (string) $row->description,
                'source' => (string) $row->source,
                'source_label' => JournalSource::from((string) $row->source)->label(),
                'amount' => (int) $row->amount,
                'status' => (string) $row->status,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'last_page' => $lastPage];
    }

    /**
     * Bulan yang punya jurnal (terbaru dulu), "YYYY-MM".
     *
     * @return list<string>
     */
    public function months(string $tenantId): array
    {
        $months = [];

        foreach (DB::table('fn_journals')
            ->where('tenant_id', $tenantId)
            ->selectRaw("DISTINCT to_char(journal_date, 'YYYY-MM') AS month")
            ->orderByDesc('month')
            ->pluck('month') as $month) {
            $months[] = (string) $month;
        }

        return $months;
    }

    /**
     * Rincian satu jurnal, atau null bila tidak ada / di luar lingkup yang boleh dilihat ($allowedOrganizations null =
     * semua; selain itu jurnal harus punya baris di salah satu lembaga itu).
     *
     * @param  list<string>|null  $allowedOrganizations
     * @return array{journal: array{id: string, number: string, date: string, description: string, source: string, source_label: string, amount: int, status: string, void_reason: string|null, created_at: string, updated_at: string}, lines: list<JournalLineRow>, source_url: string|null}|null
     */
    public function find(string $tenantId, string $journalId, ?array $allowedOrganizations): ?array
    {
        if (! Str::isUuid($journalId)) {
            return null;
        }

        $journal = DB::table('fn_journals')->where('tenant_id', $tenantId)->where('id', $journalId)->first();

        if ($journal === null) {
            return null;
        }

        $lines = [];
        $visible = $allowedOrganizations === null;

        foreach (DB::table('fn_journal_lines as l')
            ->join('fn_ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'l.organization_id')
            ->leftJoin('fn_cash_accounts as c', 'c.id', '=', 'l.cash_account_id')
            ->where('l.journal_id', $journalId)
            ->orderBy('l.line_no')
            ->get(['a.code', 'a.name', 'l.organization_id', 'o.name as organization', 'c.name as cash_account', 'l.debit', 'l.credit']) as $line) {
            if (! $visible && $line->organization_id !== null && in_array((string) $line->organization_id, $allowedOrganizations, true)) {
                $visible = true;
            }

            $lines[] = [
                'account_code' => (string) $line->code,
                'account_name' => (string) $line->name,
                'organization' => $line->organization === null ? 'Yayasan' : (string) $line->organization,
                'cash_account' => $line->cash_account === null ? null : (string) $line->cash_account,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
            ];
        }

        if (! $visible) {
            return null;
        }

        $source = JournalSource::from((string) $journal->source);

        return [
            'journal' => [
                'id' => (string) $journal->id,
                'number' => (string) $journal->number,
                'date' => substr((string) $journal->journal_date, 0, 10),
                'description' => (string) $journal->description,
                'source' => $source->value,
                'source_label' => $source->label(),
                'amount' => (int) $journal->amount,
                'status' => (string) $journal->status,
                'void_reason' => $journal->void_reason === null ? null : (string) $journal->void_reason,
                'created_at' => CarbonImmutable::parse((string) $journal->created_at)->toIso8601String(),
                'updated_at' => CarbonImmutable::parse((string) $journal->updated_at)->toIso8601String(),
            ],
            'lines' => $lines,
            'source_url' => $journal->source_id === null ? null : $this->sourceUrl($tenantId, $source, (string) $journal->source_id),
        ];
    }

    /**
     * Tautan ke transaksi sumber di halaman yang sudah ada (kuitansi, slip, kasir, kas, pengeluaran).
     */
    private function sourceUrl(string $tenantId, JournalSource $source, string $sourceId): ?string
    {
        if ($source === JournalSource::Invoice) {
            $invoice = DB::table('fn_invoices')->where('tenant_id', $tenantId)->where('id', $sourceId)->first(['organization_id', 'enrollment_id']);

            return $invoice === null ? null : '/keuangan/kasir?'.http_build_query(['lembaga' => (string) $invoice->organization_id, 'siswa' => (string) $invoice->enrollment_id]);
        }

        if ($source === JournalSource::CashEntry) {
            $entry = DB::table('fn_cash_entries')->where('tenant_id', $tenantId)->where('id', $sourceId)->first(['organization_id', 'entry_date', 'source', 'source_id']);

            if ($entry === null) {
                return null;
            }

            return match ((string) $entry->source) {
                CashEntrySource::Payment->value => '/keuangan/kuitansi/'.((string) $entry->source_id),
                CashEntrySource::Savings->value => '/keuangan/slip-tabungan/'.((string) $entry->source_id),
                CashEntrySource::Expense->value => '/keuangan/pengeluaran?'.http_build_query(['kas' => $entry->organization_id === null ? 'yayasan' : (string) $entry->organization_id]),
                default => $this->cashBookUrl($entry->organization_id, (string) $entry->entry_date),
            };
        }

        if ($source === JournalSource::Transfer) {
            $transfer = DB::table('fn_cash_transfers as t')
                ->join('fn_cash_accounts as a', 'a.id', '=', 't.from_account_id')
                ->where('t.tenant_id', $tenantId)
                ->where('t.id', $sourceId)
                ->first(['a.organization_id', 't.transfer_date']);

            return $transfer === null ? null : $this->cashBookUrl($transfer->organization_id, (string) $transfer->transfer_date);
        }

        if ($source === JournalSource::Opening) {
            return '/keuangan/pembukuan/pengaturan?'.http_build_query(['tab' => 'saldo-awal', 'lingkup' => $sourceId === $tenantId ? 'yayasan' : $sourceId]);
        }

        if ($source === JournalSource::AccountOpening) {
            $account = DB::table('fn_cash_accounts')->where('tenant_id', $tenantId)->where('id', $sourceId)->first(['organization_id', 'opening_date']);

            return $account === null ? null : $this->cashBookUrl($account->organization_id, (string) $account->opening_date);
        }

        return null;
    }

    private function cashBookUrl(mixed $organizationId, string $date): string
    {
        return '/keuangan/kas?'.http_build_query(['kas' => $organizationId === null ? 'yayasan' : (string) $organizationId, 'bulan' => substr($date, 0, 7)]);
    }

    private function filtered(string $tenantId, ?string $scope, ?string $month, ?JournalSource $source, ?string $status, string $search): Builder
    {
        $query = DB::table('fn_journals as j')->where('j.tenant_id', $tenantId);

        if ($scope !== null) {
            $query->whereExists(fn (Builder $lines) => $lines
                ->select(DB::raw(1))
                ->from('fn_journal_lines as l')
                ->whereColumn('l.journal_id', 'j.id')
                ->when($scope === '', fn (Builder $q) => $q->whereNull('l.organization_id'), fn (Builder $q) => $q->where('l.organization_id', $scope)));
        }

        if ($month !== null) {
            $first = CarbonImmutable::createFromFormat('!Y-m', $month, BusinessClock::timezone());

            if ($first !== null) {
                $query->whereBetween('j.journal_date', [$first->toDateString(), $first->endOfMonth()->toDateString()]);
            }
        }

        if ($source !== null) {
            $query->where('j.source', $source->value);
        }

        if ($status !== null) {
            $query->where('j.status', $status);
        }

        $search = trim($search);

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(mb_substr($search, 0, self::SEARCH_MAX_LENGTH))).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('lower(j.number) LIKE ?', [$like])->orWhereRaw('lower(j.description) LIKE ?', [$like]));
        }

        return $query;
    }
}
