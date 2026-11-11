<?php

namespace Modules\Finance\Application\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Ledger\JournalSource;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Jurnal manual (PRD-007 §6, PB-2a). Hak akses (finance.ledger.manage tingkat Yayasan) dicek pemanggil.
 *
 * Aturan [ASUMSI PB-2a]:
 * - tanggal: sejak tanggal mulai pembukuan s.d. akhir bulan berjalan; uraian 3–200 karakter;
 * - 2–50 baris; tiap baris: akun posting aktif selain akun kas/bank & akun otomatis terkunci, lembaga (kosong =
 *   Yayasan), dan debit ATAU kredit Rp 1 s.d. Rp 999.999.999.999; total debit = total kredit;
 * - tidak bisa diubah; salah → batalkan (alasan 1–200) lalu catat ulang. Tanpa persetujuan (OD-PB-09), tercatat di audit.
 */
final class ManualJournals
{
    public const MAX_LINES = 50;

    public const MAX_AMOUNT = 999_999_999_999;

    public const DESCRIPTION_MAX_LENGTH = 200;

    public const REASON_MAX_LENGTH = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ChartOfAccounts $accounts,
        private readonly LedgerLock $lock,
    ) {}

    /**
     * Rentang tanggal yang boleh dipakai: [tanggal mulai (atau sehari sesudah periode terkunci), akhir bulan
     * berjalan], atau null bila pembukuan belum aktif.
     *
     * @return array{0: string, 1: string}|null
     */
    public function dateRange(string $tenantId): ?array
    {
        $row = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->first(['start_date', 'locked_through']);

        if ($row === null || $row->start_date === null) {
            return null;
        }

        $first = $row->locked_through === null
            ? substr((string) $row->start_date, 0, 10)
            : CarbonImmutable::parse(substr((string) $row->locked_through, 0, 10))->addDay()->toDateString();

        return [$first, BusinessClock::today()->endOfMonth()->toDateString()];
    }

    /**
     * @param  mixed  $lines  daftar baris dari formulir: [{account_id, organization_id, debit, credit}]
     *
     * @throws FinanceException
     */
    public function post(string $tenantId, ?string $membershipId, string $date, string $description, mixed $lines): string
    {
        $range = $this->dateRange($tenantId);

        if ($range === null) {
            throw FinanceException::make('form', 'Atur tanggal mulai pembukuan dulu.');
        }

        $this->lock->assertOpen($tenantId, trim($date), 'date');
        $date = $this->date($date, $range);
        $description = trim((string) preg_replace('/\s+/u', ' ', $description));

        if (mb_strlen($description) < 3 || mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw FinanceException::make('description', 'Uraian wajib diisi, 3–200 karakter.');
        }

        $parsed = $this->lines($tenantId, $lines);
        $amount = array_sum(array_column($parsed, 'debit'));
        $organizations = array_unique(array_map(fn (array $line): string => $line['organization_id'] ?? '', $parsed));
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $membershipId, $id, $date, $description, $parsed, $amount, $organizations): void {
            $sequence = (int) DB::selectOne("SELECT nextval('fn_journal_number_seq') AS n")?->n;
            $number = sprintf('JU-%s-%06d', substr($date, 0, 4), $sequence);

            DB::table('fn_journals')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'number' => $number,
                'journal_date' => $date,
                'description' => $description,
                'source' => JournalSource::Manual->value,
                'source_id' => null,
                'organization_id' => count($organizations) === 1 && $organizations[array_key_first($organizations)] !== '' ? $organizations[array_key_first($organizations)] : null,
                'amount' => $amount,
                'status' => PaymentStatus::Valid->value,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rows = [];

            foreach ($parsed as $index => $line) {
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'journal_id' => $id, 'line_no' => $index + 1, 'cash_account_id' => null] + $line;
            }

            DB::table('fn_journal_lines')->insert($rows);

            $this->audit->record(
                FinanceAuditAction::JOURNAL_POSTED,
                FinanceAuditAction::SUBJECT_JOURNAL,
                $id,
                ['nomor' => $number, 'tanggal' => $date, 'jumlah' => $amount, 'baris' => count($parsed)],
                tenantId: $tenantId,
            );
        });

        return $id;
    }

    /**
     * Batalkan jurnal manual (jurnal otomatis ikut batal hanya lewat transaksi sumbernya).
     *
     * @throws FinanceException
     */
    public function void(string $tenantId, string $journalId, string $reason): void
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', 'Alasan pembatalan wajib diisi, maksimal 200 karakter.');
        }

        DB::transaction(function () use ($tenantId, $journalId, $reason): void {
            $journal = Str::isUuid($journalId)
                ? DB::table('fn_journals')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first()
                : null;

            if ($journal === null) {
                throw FinanceException::make('form', 'Jurnal tidak ditemukan.');
            }

            if ((string) $journal->source !== JournalSource::Manual->value) {
                throw FinanceException::make('form', 'Jurnal otomatis hanya bisa batal lewat transaksi sumbernya.');
            }

            if ((string) $journal->status !== PaymentStatus::Valid->value) {
                throw FinanceException::make('form', 'Jurnal ini sudah dibatalkan.');
            }

            $this->lock->assertChangeable($tenantId, (string) $journal->journal_date);

            DB::table('fn_journals')->where('id', $journalId)->update([
                'status' => PaymentStatus::Void->value,
                'void_reason' => $reason,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::JOURNAL_VOIDED,
                FinanceAuditAction::SUBJECT_JOURNAL,
                $journalId,
                ['nomor' => (string) $journal->number, 'alasan' => $reason],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @param  array{0: string, 1: string}  $range
     *
     * @throws FinanceException
     */
    private function date(string $value, array $range): string
    {
        $value = trim($value);
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

        if ($parsed === null || $parsed->format('Y-m-d') !== $value) {
            throw FinanceException::make('date', 'Isi tanggal jurnal yang valid.');
        }

        if ($value < $range[0]) {
            throw FinanceException::make('date', 'Tanggal jurnal tidak boleh sebelum tanggal mulai pembukuan ('.LedgerDate::long($range[0]).').');
        }

        if ($value > $range[1]) {
            throw FinanceException::make('date', 'Tanggal jurnal paling lambat akhir bulan ini.');
        }

        return $value;
    }

    /**
     * @return list<array{account_id: string, organization_id: string|null, debit: int, credit: int}>
     *
     * @throws FinanceException
     */
    private function lines(string $tenantId, mixed $input): array
    {
        $lines = [];

        foreach (is_array($input) ? array_values($input) : [] as $index => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $account = trim((string) ($raw['account_id'] ?? ''));
            $debitText = trim((string) ($raw['debit'] ?? ''));
            $creditText = trim((string) ($raw['credit'] ?? ''));

            // Baris kosong di formulir diabaikan.
            if ($account === '' && $debitText === '' && $creditText === '') {
                continue;
            }

            $prefix = 'Baris '.($index + 1).': ';
            $this->accounts->assertManualAccount($tenantId, $account, false, 'lines', $prefix);
            $organizationId = $this->accounts->ledgerOrganization($tenantId, isset($raw['organization_id']) ? (string) $raw['organization_id'] : null, 'lines', $prefix);

            if (($debitText === '') === ($creditText === '')) {
                throw FinanceException::make('lines', $prefix.'isi debit ATAU kredit (salah satu).');
            }

            $debit = $debitText === '' ? 0 : Rupiah::parse($debitText, 'lines', $prefix.'debit', 1, self::MAX_AMOUNT);
            $credit = $creditText === '' ? 0 : Rupiah::parse($creditText, 'lines', $prefix.'kredit', 1, self::MAX_AMOUNT);

            $lines[] = ['account_id' => $account, 'organization_id' => $organizationId, 'debit' => $debit, 'credit' => $credit];
        }

        if (count($lines) < 2) {
            throw FinanceException::make('lines', 'Jurnal minimal 2 baris.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw FinanceException::make('lines', 'Jurnal maksimal '.self::MAX_LINES.' baris.');
        }

        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));

        if ($debit !== $credit) {
            throw FinanceException::make('lines', 'Total debit ('.Rupiah::format($debit).') harus sama dengan total kredit ('.Rupiah::format($credit).').');
        }

        return $lines;
    }
}
