<?php

namespace Modules\Finance\Application\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Cash\CashAccountKind;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Invoice\InvoiceStatus;
use Modules\Finance\Domain\Ledger\JournalSource;
use Modules\Finance\Domain\Payment\PaymentStatus;
use stdClass;

/**
 * Jurnal otomatis (PRD-007 §4–§5, PB-1, PB-2a): proyeksi transaksi aplikasi ke jurnal berpasangan.
 *
 * Sumber: tagihan (TAGIHAN), transaksi kas selain mutasi (KAS), mutasi (MUTASI, dua baris kas = satu jurnal), saldo awal
 * rekening kas yang dibuka pada/sesudah tanggal mulai pembukuan (SALDO_REKENING), dan saldo awal per lembaga pada tanggal
 * mulai (SALDO_AWAL, termasuk baris tambahan akuntan).
 *
 * Proyeksi bersifat deterministik & aman diulang: untuk tiap sumber dihitung jurnal yang seharusnya, lalu dibandingkan
 * dengan yang tersimpan —
 * - sumber hilang (cerminan kas disusun ulang) atau bertanggal sebelum tanggal mulai → jurnalnya dihapus;
 * - sumber dibatalkan → jurnalnya BATAL di tanggal aslinya (OD-PB-08); sumber batal yang belum pernah dijurnal tidak dibuat;
 * - selain itu jurnal dibuat / diperbarui (nomor tetap);
 * - jurnal bertanggal di periode terkunci (PB-4) dibekukan: tidak dibuat, diubah, atau dihapus lagi.
 *
 * Perubahan sumber masuk `fn_ledger_queue` lewat trigger database; `processQueue()` dijalankan scheduler tiap menit dan
 * sebelum halaman pembukuan dibuka. `rebuildStep()` menyusun ulang semua sumber bertahap (sesudah tanggal mulai /
 * pemetaan berubah).
 *
 * @phpstan-type Line array{account_id: string, organization_id: string|null, cash_account_id: string|null, debit: int, credit: int}
 * @phpstan-type Desired array{date: string, description: string, organization_id: string|null, valid: bool, void_reason: string|null, lines: list<Line>}
 */
final class JournalProjector
{
    public const BATCH = 500;

    private const PHASES = ['SALDO_REKENING', 'TAGIHAN', 'KAS', 'MUTASI', 'SALDO_AWAL', 'BERSIHKAN'];

    /** @var array<string, string> */
    private array $keyed = [];

    /** @var array<string, array{ledger_account_id: string, organization_id: string|null, kind: string, name: string}> */
    private array $cashAccounts = [];

    /** @var array<string, array{ledger_account_id: string|null, direction: string}> */
    private array $categories = [];

    /** @var array<string, string|null> */
    private array $feeTypes = [];

    /** Tanggal terakhir periode terkunci (PB-4), null = tidak ada kunci. */
    private ?string $lockedThrough = null;

    /** @var array<string, string> */
    private array $organizationNames = [];

    public function __construct(
        private readonly ChartOfAccounts $accounts,
        private readonly EnrolledStudents $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Susun jurnal dari antrean (paling banyak $limit sumber). Mengembalikan jumlah yang masih menunggu.
     */
    public function processQueue(string $tenantId, int $limit = 2000): int
    {
        $start = $this->startDate($tenantId);

        if ($start === null) {
            DB::table('fn_ledger_queue')->where('tenant_id', $tenantId)->delete();

            return 0;
        }

        $processed = 0;

        while ($processed < $limit) {
            $count = DB::transaction(function () use ($tenantId, $start): int {
                $rows = DB::table('fn_ledger_queue')
                    ->where('tenant_id', $tenantId)
                    ->orderBy('queued_at')
                    ->limit(self::BATCH)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->get(['source', 'source_id']);

                if ($rows->isEmpty()) {
                    return 0;
                }

                $grouped = [];

                foreach ($rows as $row) {
                    $grouped[(string) $row->source][] = (string) $row->source_id;
                }

                $this->load($tenantId);

                foreach ($grouped as $source => $ids) {
                    $this->project($tenantId, JournalSource::from($source), $ids, $start);
                    DB::table('fn_ledger_queue')->where('source', $source)->whereIn('source_id', $ids)->delete();
                }

                return $rows->count();
            });

            if ($count === 0) {
                break;
            }

            $processed += $count;
        }

        return DB::table('fn_ledger_queue')->where('tenant_id', $tenantId)->count();
    }

    /**
     * Satu langkah susun ulang (beberapa potongan, ±$seconds detik). Mulai otomatis bila perlu.
     *
     * @return array{done: int, total: int, finished: bool}
     *
     * @throws FinanceException pembukuan belum aktif
     */
    public function rebuildStep(string $tenantId, float $seconds = 5.0): array
    {
        $deadline = microtime(true) + $seconds;

        do {
            $state = DB::transaction(function () use ($tenantId): array {
                $settings = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first();

                if ($settings === null || $settings->start_date === null) {
                    throw FinanceException::make('form', 'Atur tanggal mulai pembukuan dulu.');
                }

                $start = substr((string) $settings->start_date, 0, 10);

                if ($settings->rebuild_version === null) {
                    if ((int) $settings->rebuilt_version >= (int) $settings->config_version) {
                        return ['done' => (int) $settings->rebuild_done, 'total' => (int) $settings->rebuild_total, 'finished' => true];
                    }

                    $settings = $this->beginRebuild($tenantId, (int) $settings->config_version);
                }

                $this->load($tenantId);

                return $this->advance($tenantId, $settings, $start);
            });
        } while (! $state['finished'] && microtime(true) < $deadline);

        return $state;
    }

    /**
     * Susun ulang sampai selesai (perintah artisan / scheduler).
     */
    public function rebuildAll(string $tenantId): void
    {
        do {
            $state = $this->rebuildStep($tenantId, 30.0);
        } while (! $state['finished']);
    }

    private function beginRebuild(string $tenantId, int $version): stdClass
    {
        $total = DB::table('fn_cash_accounts')->where('tenant_id', $tenantId)->count()
            + DB::table('fn_invoices')->where('tenant_id', $tenantId)->count()
            + DB::table('fn_cash_entries')->where('tenant_id', $tenantId)->where('source', '!=', CashEntrySource::Transfer->value)->count()
            + DB::table('fn_cash_transfers')->where('tenant_id', $tenantId)->count()
            + count($this->openingOwners($tenantId));

        DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update([
            'rebuild_version' => $version,
            'rebuild_phase' => self::PHASES[0],
            'rebuild_cursor' => null,
            'rebuild_done' => 0,
            'rebuild_total' => $total,
            'rebuild_started_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->first() ?? new stdClass;
    }

    /**
     * @return array{done: int, total: int, finished: bool}
     */
    private function advance(string $tenantId, stdClass $settings, string $start): array
    {
        $phase = (string) $settings->rebuild_phase;
        $done = (int) $settings->rebuild_done;
        $total = (int) $settings->rebuild_total;

        if ($phase === 'BERSIHKAN') {
            $this->deleteOrphans($tenantId);

            DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update([
                'rebuilt_version' => $settings->rebuild_version,
                'rebuild_version' => null,
                'rebuild_phase' => null,
                'rebuild_cursor' => null,
                'rebuild_done' => $total,
                'rebuilt_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::JOURNALS_REBUILT,
                FinanceAuditAction::SUBJECT_LEDGER_SETTINGS,
                $tenantId,
                ['tanggal_mulai' => $start, 'jumlah_transaksi' => $total],
                tenantId: $tenantId,
            );

            return ['done' => $total, 'total' => $total, 'finished' => true];
        }

        $source = JournalSource::from($phase);

        // Saldo awal: satu jurnal per lembaga + Yayasan, cukup satu potongan.
        if ($source === JournalSource::Opening) {
            $owners = $this->openingOwners($tenantId);
            $this->project($tenantId, $source, $owners, $start);
            $done = min($total, $done + count($owners));

            DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update([
                'rebuild_phase' => 'BERSIHKAN',
                'rebuild_cursor' => null,
                'rebuild_done' => $done,
                'updated_at' => now(),
            ]);

            return ['done' => $done, 'total' => $total, 'finished' => false];
        }

        $query = match ($source) {
            JournalSource::AccountOpening => DB::table('fn_cash_accounts'),
            JournalSource::Invoice => DB::table('fn_invoices'),
            JournalSource::CashEntry => DB::table('fn_cash_entries')->where('source', '!=', CashEntrySource::Transfer->value),
            default => DB::table('fn_cash_transfers'),
        };
        $ids = [];

        foreach ($query->where('tenant_id', $tenantId)
            ->when($settings->rebuild_cursor !== null, fn ($q) => $q->where('id', '>', $settings->rebuild_cursor))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id') as $id) {
            $ids[] = (string) $id;
        }

        if ($ids !== []) {
            $this->project($tenantId, $source, $ids, $start);
        }

        $last = count($ids) < self::BATCH;
        $next = $last ? (self::PHASES[(int) array_search($phase, self::PHASES, true) + 1] ?? 'BERSIHKAN') : $phase;
        $done = min($total, $done + count($ids));

        DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update([
            'rebuild_phase' => $next,
            'rebuild_cursor' => $last ? null : end($ids),
            'rebuild_done' => $done,
            'updated_at' => now(),
        ]);

        return ['done' => $done, 'total' => $total, 'finished' => false];
    }

    /**
     * Jurnal otomatis yang sumbernya sudah tidak ada (mis. cerminan kas yang disusun ulang).
     */
    private function deleteOrphans(string $tenantId): void
    {
        foreach ([
            JournalSource::AccountOpening->value => 'fn_cash_accounts',
            JournalSource::Invoice->value => 'fn_invoices',
            JournalSource::CashEntry->value => 'fn_cash_entries',
            JournalSource::Transfer->value => 'fn_cash_transfers',
        ] as $source => $table) {
            DB::table('fn_journals as j')
                ->where('j.tenant_id', $tenantId)
                ->where('j.source', $source)
                ->when($this->lockedThrough !== null, fn ($query) => $query->where('j.journal_date', '>', $this->lockedThrough))
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from($table.' as s')->whereColumn('s.id', 'j.source_id'))
                ->delete();
        }
    }

    /**
     * Samakan jurnal sumber-sumber ini dengan keadaan sumbernya. Dipanggil di dalam transaksi database.
     *
     * @param  list<string>  $ids
     */
    private function project(string $tenantId, JournalSource $source, array $ids, string $start): void
    {
        $desired = match ($source) {
            JournalSource::Invoice => $this->invoices($tenantId, $ids, $start),
            JournalSource::CashEntry => $this->cashEntries($tenantId, $ids, $start),
            JournalSource::Transfer => $this->transfers($tenantId, $ids, $start),
            JournalSource::AccountOpening => $this->accountOpenings($tenantId, $ids, $start),
            JournalSource::Opening => $this->openings($tenantId, $ids, $start),
            default => [],
        };

        $existing = [];

        foreach (DB::table('fn_journals')
            ->where('tenant_id', $tenantId)
            ->where('source', $source->value)
            ->whereIn('source_id', $ids)
            ->lockForUpdate()
            ->get(['id', 'source_id', 'journal_date', 'description', 'organization_id', 'amount', 'status', 'void_reason']) as $journal) {
            $existing[(string) $journal->source_id] = $journal;
        }

        $lines = [];

        if ($existing !== []) {
            foreach (DB::table('fn_journal_lines')
                ->whereIn('journal_id', array_map(fn (stdClass $journal): string => (string) $journal->id, array_values($existing)))
                ->orderBy('line_no')
                ->get(['journal_id', 'account_id', 'organization_id', 'cash_account_id', 'debit', 'credit']) as $line) {
                $lines[(string) $line->journal_id][] = [
                    'account_id' => (string) $line->account_id,
                    'organization_id' => $line->organization_id === null ? null : (string) $line->organization_id,
                    'cash_account_id' => $line->cash_account_id === null ? null : (string) $line->cash_account_id,
                    'debit' => (int) $line->debit,
                    'credit' => (int) $line->credit,
                ];
            }
        }

        $delete = [];
        $replaceLines = [];
        $create = [];

        foreach ($ids as $id) {
            $want = $desired[$id] ?? null;
            $have = $existing[$id] ?? null;

            // Periode terkunci: jurnal dibekukan apa adanya (sumbernya sendiri sudah dijaga agar tidak berubah).
            if ($this->frozen($have === null ? null : substr((string) $have->journal_date, 0, 10)) || $this->frozen($want['date'] ?? null)) {
                continue;
            }

            if ($have === null) {
                if ($want !== null && $want['valid']) {
                    $create[$id] = $want;
                }

                continue;
            }

            if ($want === null) {
                $delete[] = (string) $have->id;

                continue;
            }

            $amount = array_sum(array_column($want['lines'], 'debit'));
            $header = [
                'journal_date' => $want['date'],
                'description' => $want['description'],
                'organization_id' => $want['organization_id'],
                'amount' => $amount,
                'status' => $want['valid'] ? PaymentStatus::Valid->value : PaymentStatus::Void->value,
                'void_reason' => $want['valid'] ? null : $want['void_reason'],
            ];
            $current = [
                'journal_date' => substr((string) $have->journal_date, 0, 10),
                'description' => (string) $have->description,
                'organization_id' => $have->organization_id === null ? null : (string) $have->organization_id,
                'amount' => (int) $have->amount,
                'status' => (string) $have->status,
                'void_reason' => $have->void_reason === null ? null : (string) $have->void_reason,
            ];

            if ($header !== $current) {
                DB::table('fn_journals')->where('id', $have->id)->update($header + ['updated_at' => now()]);
            }

            if (($lines[(string) $have->id] ?? []) !== $want['lines']) {
                $replaceLines[(string) $have->id] = $want['lines'];
            }
        }

        if ($delete !== []) {
            DB::table('fn_journals')->whereIn('id', $delete)->delete();
        }

        if ($replaceLines !== []) {
            DB::table('fn_journal_lines')->whereIn('journal_id', array_keys($replaceLines))->delete();
            $this->insertLines($tenantId, $replaceLines);
        }

        if ($create !== []) {
            $this->create($tenantId, $source, $create);
        }
    }

    /**
     * @param  array<string, Desired>  $journals  id sumber → jurnal
     */
    private function create(string $tenantId, JournalSource $source, array $journals): void
    {
        $numbers = [];

        foreach (DB::select('SELECT nextval(\'fn_journal_number_seq\') AS n FROM generate_series(1, ?)', [count($journals)]) as $row) {
            $numbers[] = (int) $row->n;
        }

        $now = now();
        $headers = [];
        $lines = [];
        $index = 0;

        foreach ($journals as $sourceId => $journal) {
            $id = (string) Str::uuid7();
            $headers[] = [
                'id' => $id,
                'tenant_id' => $tenantId,
                'number' => sprintf('JU-%s-%06d', substr($journal['date'], 0, 4), $numbers[$index++]),
                'journal_date' => $journal['date'],
                'description' => $journal['description'],
                'source' => $source->value,
                'source_id' => $sourceId,
                'organization_id' => $journal['organization_id'],
                'amount' => array_sum(array_column($journal['lines'], 'debit')),
                'status' => PaymentStatus::Valid->value,
                'void_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $lines[$id] = $journal['lines'];
        }

        foreach (array_chunk($headers, 500) as $chunk) {
            DB::table('fn_journals')->insert($chunk);
        }

        $this->insertLines($tenantId, $lines);
    }

    /**
     * @param  array<string, list<Line>>  $journals  id jurnal → baris
     */
    private function insertLines(string $tenantId, array $journals): void
    {
        $rows = [];

        foreach ($journals as $journalId => $lines) {
            foreach ($lines as $number => $line) {
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'journal_id' => $journalId, 'line_no' => $number + 1] + $line;
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('fn_journal_lines')->insert($chunk);
        }
    }

    /**
     * Tagihan terbit (akrual, OD-PB-03): Dr Piutang siswa (nominal akhir) + Dr Potongan & beasiswa (potongan) /
     * Cr pendapatan jenis biaya (tarif, OD-PB-06). Tanggal: bulan periode (bulanan) atau tanggal dibuat (sekali bayar).
     *
     * @param  list<string>  $ids
     * @return array<string, Desired>
     */
    private function invoices(string $tenantId, array $ids, string $start): array
    {
        $rows = DB::table('fn_invoices')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'organization_id', 'enrollment_id', 'fee_type_id', 'period', 'description', 'discount_amount', 'amount', 'status', 'void_reason', 'created_at']);

        $enrollmentIds = [];

        foreach ($rows as $row) {
            $enrollmentIds[(string) $row->enrollment_id] = true;
        }

        $names = $enrollmentIds === [] ? [] : $this->students->describe($tenantId, array_keys($enrollmentIds));
        $desired = [];

        foreach ($rows as $row) {
            $date = $row->period !== null
                ? substr((string) $row->period, 0, 10)
                : CarbonImmutable::parse((string) $row->created_at)->setTimezone(BusinessClock::timezone())->toDateString();

            if ($date < $start) {
                continue;
            }

            $organizationId = (string) $row->organization_id;
            $amount = (int) $row->amount;
            $discount = (int) $row->discount_amount;
            $lines = [];

            if ($amount > 0) {
                $lines[] = $this->line($this->keyed[ChartOfAccounts::KEY_RECEIVABLE], $organizationId, null, $amount, 0);
            }

            if ($discount > 0) {
                $lines[] = $this->line($this->keyed[ChartOfAccounts::KEY_DISCOUNT], $organizationId, null, $discount, 0);
            }

            if ($amount + $discount <= 0) {
                continue;
            }

            $lines[] = $this->line($this->feeTypes[(string) $row->fee_type_id] ?? $this->keyed[ChartOfAccounts::KEY_FEE_REVENUE], $organizationId, null, 0, $amount + $discount);
            $student = $names[(string) $row->enrollment_id] ?? null;
            $valid = (string) $row->status !== InvoiceStatus::Void->value;

            $desired[(string) $row->id] = [
                'date' => $date,
                'description' => $this->text('Tagihan '.((string) $row->description).($student === null ? '' : ' — '.$student['name'].($student['class'] === null ? '' : " ({$student['class']})"))),
                'organization_id' => $organizationId,
                'valid' => $valid,
                'void_reason' => $valid ? null : $this->reason('Tagihan dibatalkan', $row->void_reason),
                'lines' => $lines,
            ];
        }

        return $desired;
    }

    /**
     * Transaksi kas selain mutasi: rekening kas vs akun lawan — Piutang siswa (cerminan kuitansi), Utang titipan
     * tabungan (tabungan), atau akun kategori (pemasukan manual, realisasi pengeluaran).
     *
     * @param  list<string>  $ids
     * @return array<string, Desired>
     */
    private function cashEntries(string $tenantId, array $ids, string $start): array
    {
        $desired = [];

        foreach (DB::table('fn_cash_entries')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->where('source', '!=', CashEntrySource::Transfer->value)
            ->get(['id', 'organization_id', 'account_id', 'category_id', 'entry_date', 'direction', 'amount', 'description', 'source', 'status', 'void_reason']) as $row) {
            $date = substr((string) $row->entry_date, 0, 10);
            $account = $this->cashAccounts[(string) $row->account_id] ?? null;

            if ($date < $start || $account === null || (int) $row->amount <= 0) {
                continue;
            }

            $category = $this->categories[(string) $row->category_id] ?? null;
            $in = (string) $row->direction === CashDirection::In->value;
            $counter = match ((string) $row->source) {
                CashEntrySource::Payment->value => $this->keyed[ChartOfAccounts::KEY_RECEIVABLE],
                CashEntrySource::Savings->value => $this->keyed[ChartOfAccounts::KEY_SAVINGS_LIABILITY],
                default => $category['ledger_account_id'] ?? $this->keyed[$in ? ChartOfAccounts::KEY_OTHER_INCOME : ChartOfAccounts::KEY_OTHER_EXPENSE],
            };
            $organizationId = $row->organization_id === null ? null : (string) $row->organization_id;
            $amount = (int) $row->amount;
            $cashLine = $this->line($account['ledger_account_id'], $organizationId, (string) $row->account_id, $in ? $amount : 0, $in ? 0 : $amount);
            $counterLine = $this->line($counter, $organizationId, null, $in ? 0 : $amount, $in ? $amount : 0);
            $valid = (string) $row->status === PaymentStatus::Valid->value;

            $desired[(string) $row->id] = [
                'date' => $date,
                'description' => $this->text((string) $row->description),
                'organization_id' => $organizationId,
                'valid' => $valid,
                'void_reason' => $valid ? null : $this->reason('Transaksi kas dibatalkan', $row->void_reason, false),
                'lines' => $in ? [$cashLine, $counterLine] : [$counterLine, $cashLine],
            ];
        }

        return $desired;
    }

    /**
     * Mutasi: satu kas → Dr rekening tujuan / Cr rekening asal; antar-kas → lewat Rekening antar-kantor di kedua kas
     * (PRD-007 §3.1), sehingga buku tiap lembaga tetap seimbang dan RAK gabungan = 0.
     *
     * @param  list<string>  $ids
     * @return array<string, Desired>
     */
    private function transfers(string $tenantId, array $ids, string $start): array
    {
        $desired = [];

        foreach (DB::table('fn_cash_transfers')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'from_account_id', 'to_account_id', 'transfer_date', 'amount', 'description', 'status', 'void_reason']) as $row) {
            $date = substr((string) $row->transfer_date, 0, 10);
            $from = $this->cashAccounts[(string) $row->from_account_id] ?? null;
            $to = $this->cashAccounts[(string) $row->to_account_id] ?? null;
            $amount = (int) $row->amount;

            if ($date < $start || $from === null || $to === null || $amount <= 0) {
                continue;
            }

            $fromOrg = $from['organization_id'];
            $toOrg = $to['organization_id'];
            $fromLine = $this->line($from['ledger_account_id'], $fromOrg, (string) $row->from_account_id, 0, $amount);
            $toLine = $this->line($to['ledger_account_id'], $toOrg, (string) $row->to_account_id, $amount, 0);
            $lines = $fromOrg === $toOrg
                ? [$toLine, $fromLine]
                : [
                    $this->line($this->keyed[ChartOfAccounts::KEY_INTER_OFFICE], $fromOrg, null, $amount, 0),
                    $fromLine,
                    $toLine,
                    $this->line($this->keyed[ChartOfAccounts::KEY_INTER_OFFICE], $toOrg, null, 0, $amount),
                ];
            $valid = (string) $row->status === PaymentStatus::Valid->value;

            $desired[(string) $row->id] = [
                'date' => $date,
                'description' => $this->text("Mutasi {$this->accountLabel($from)} → {$this->accountLabel($to)} — ".((string) $row->description)),
                'organization_id' => $fromOrg,
                'valid' => $valid,
                'void_reason' => $valid ? null : $this->reason('Mutasi dibatalkan', $row->void_reason),
                'lines' => $lines,
            ];
        }

        return $desired;
    }

    /**
     * Saldo awal rekening kas yang dibuka pada/sesudah tanggal mulai pembukuan: Dr akun rekening / Cr Aset neto tanpa
     * pembatasan (rekening Titipan: Cr Utang titipan tabungan). Rekening yang dibuka sebelumnya masuk jurnal saldo awal
     * (PB-2).
     *
     * @param  list<string>  $ids
     * @return array<string, Desired>
     */
    private function accountOpenings(string $tenantId, array $ids, string $start): array
    {
        $desired = [];

        foreach (DB::table('fn_cash_accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'kind', 'opening_balance', 'opening_date']) as $row) {
            $date = substr((string) $row->opening_date, 0, 10);
            $amount = (int) $row->opening_balance;
            $account = $this->cashAccounts[(string) $row->id] ?? null;

            if ($date < $start || $amount === 0 || $account === null) {
                continue;
            }

            $counter = $this->keyed[(string) $row->kind === CashAccountKind::Custody->value ? ChartOfAccounts::KEY_SAVINGS_LIABILITY : ChartOfAccounts::KEY_NET_ASSETS_FREE];
            $positive = $amount > 0;
            $amount = abs($amount);
            $organizationId = $account['organization_id'];

            $desired[(string) $row->id] = [
                'date' => $date,
                'description' => $this->text("Saldo awal {$this->accountLabel($account)}"),
                'organization_id' => $organizationId,
                'valid' => true,
                'void_reason' => null,
                'lines' => [
                    $this->line($account['ledger_account_id'], $organizationId, (string) $row->id, $positive ? $amount : 0, $positive ? 0 : $amount),
                    $this->line($counter, $organizationId, null, $positive ? 0 : $amount, $positive ? $amount : 0),
                ],
            ];
        }

        // Baris debit ditaruh di atas.
        foreach ($desired as $id => $journal) {
            usort($journal['lines'], fn (array $a, array $b): int => $b['debit'] <=> $a['debit']);
            $desired[$id] = $journal;
        }

        return $desired;
    }

    /**
     * Rancangan jurnal saldo awal satu lembaga (null = Yayasan) untuk halaman Saldo awal; null bila pembukuan belum
     * aktif atau semua saldonya nol.
     *
     * @return Desired|null
     */
    public function openingPreview(string $tenantId, ?string $organizationId): ?array
    {
        $start = $this->startDate($tenantId);

        if ($start === null) {
            return null;
        }

        $this->load($tenantId);
        $owner = $organizationId ?? $tenantId;

        return $this->openings($tenantId, [$owner], $start)[$owner] ?? null;
    }

    /**
     * Antrekan ulang jurnal saldo awal lembaga ini (sesudah baris tambahan saldo awal berubah).
     */
    public function queueOpening(string $tenantId, ?string $organizationId): void
    {
        DB::table('fn_ledger_queue')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'source' => JournalSource::Opening->value,
            'source_id' => $organizationId ?? $tenantId,
            'queued_at' => now(),
        ]);
    }

    /**
     * Pemilik jurnal saldo awal: id yayasan (kas Yayasan) + semua node LEMBAGA.
     *
     * @return list<string>
     */
    private function openingOwners(string $tenantId): array
    {
        $owners = [$tenantId];

        foreach (DB::table('organizations')->where('tenant_id', $tenantId)->where('type', OrganizationType::Lembaga->value)->orderBy('id')->pluck('id') as $id) {
            $owners[] = (string) $id;
        }

        return $owners;
    }

    /**
     * Jurnal saldo awal per lembaga pada tanggal mulai (PRD-007 §5): kas & bank = saldo buku kas tiap rekening sehari
     * sebelumnya (rekening yang dibuka sebelum tanggal mulai); kas titipan = utang titipan; piutang siswa = tagihan
     * bertanggal sebelum tanggal mulai dikurangi kuitansi sebelum tanggal mulai; baris tambahan akuntan; selisihnya →
     * Aset neto tanpa pembatasan. `$ids` = id lembaga, atau id yayasan untuk kas Yayasan.
     *
     * @param  list<string>  $ids
     * @return array<string, Desired>
     */
    private function openings(string $tenantId, array $ids, string $start): array
    {
        $desired = [];
        $startUtc = CarbonImmutable::parse($start, BusinessClock::timezone())->utc();

        foreach ($ids as $owner) {
            $organizationId = $owner === $tenantId ? null : $owner;
            $debits = [];
            $credits = [];
            $scoped = fn ($query, string $column = 'organization_id') => $organizationId === null ? $query->whereNull($column) : $query->where($column, $organizationId);

            // Kas & bank: saldo awal rekening + transaksi SAH sebelum tanggal mulai.
            $accounts = $scoped(DB::table('fn_cash_accounts')->where('tenant_id', $tenantId)->where('opening_date', '<', $start))
                ->orderByRaw("CASE kind WHEN 'TUNAI' THEN 0 WHEN 'BANK' THEN 1 ELSE 2 END")
                ->orderBy('name')
                ->get(['id', 'kind', 'opening_balance']);
            $movements = [];

            if ($accounts->isNotEmpty()) {
                foreach (DB::table('fn_cash_entries')
                    ->whereIn('account_id', $accounts->pluck('id')->all())
                    ->where('status', PaymentStatus::Valid->value)
                    ->where('entry_date', '<', $start)
                    ->groupBy('account_id')
                    ->get(['account_id', DB::raw("SUM(CASE WHEN direction = 'MASUK' THEN amount ELSE -amount END) AS total")]) as $row) {
                    $movements[(string) $row->account_id] = (int) $row->total;
                }
            }

            foreach ($accounts as $account) {
                $balance = (int) $account->opening_balance + ($movements[(string) $account->id] ?? 0);
                $ledger = $this->cashAccounts[(string) $account->id]['ledger_account_id'] ?? null;

                if ($balance === 0 || $ledger === null) {
                    continue;
                }

                $this->push($debits, $credits, $ledger, $organizationId, (string) $account->id, $balance);

                if ((string) $account->kind === CashAccountKind::Custody->value) {
                    $this->push($debits, $credits, $this->keyed[ChartOfAccounts::KEY_SAVINGS_LIABILITY], $organizationId, null, -$balance);
                }
            }

            // Piutang siswa (hanya lembaga).
            if ($organizationId !== null) {
                $billed = (int) DB::table('fn_invoices')
                    ->where('tenant_id', $tenantId)
                    ->where('organization_id', $organizationId)
                    ->where('status', '!=', InvoiceStatus::Void->value)
                    ->where(fn ($query) => $query->where('period', '<', $start)->orWhere(fn ($once) => $once->whereNull('period')->where('created_at', '<', $startUtc)))
                    ->sum('amount');
                $paid = (int) DB::table('fn_payments')
                    ->where('tenant_id', $tenantId)
                    ->where('organization_id', $organizationId)
                    ->where('status', PaymentStatus::Valid->value)
                    ->where('paid_on', '<', $start)
                    ->sum('amount');

                if ($billed - $paid !== 0) {
                    $this->push($debits, $credits, $this->keyed[ChartOfAccounts::KEY_RECEIVABLE], $organizationId, null, $billed - $paid);
                }
            }

            // Baris tambahan akuntan.
            foreach ($scoped(DB::table('fn_opening_items')->where('tenant_id', $tenantId))->orderBy('created_at')->orderBy('id')->get(['account_id', 'debit', 'credit']) as $item) {
                $this->push($debits, $credits, (string) $item->account_id, $organizationId, null, (int) $item->debit - (int) $item->credit);
            }

            $difference = array_sum(array_column($debits, 'debit')) - array_sum(array_column($credits, 'credit'));

            if ($difference !== 0) {
                $this->push($debits, $credits, $this->keyed[ChartOfAccounts::KEY_NET_ASSETS_FREE], $organizationId, null, -$difference);
            }

            if ($debits === [] && $credits === []) {
                continue;
            }

            $name = $organizationId === null ? 'Kas Yayasan' : ($this->organizationNames[$organizationId] ?? 'lembaga');

            $desired[$owner] = [
                'date' => $start,
                'description' => $this->text("Saldo awal {$name} per ".LedgerDate::long($start)),
                'organization_id' => $organizationId,
                'valid' => true,
                'void_reason' => null,
                'lines' => [...$debits, ...$credits],
            ];
        }

        return $desired;
    }

    /**
     * Tambah baris saldo: positif → debit, negatif → kredit.
     *
     * @param  list<Line>  $debits
     * @param  list<Line>  $credits
     */
    private function push(array &$debits, array &$credits, string $accountId, ?string $organizationId, ?string $cashAccountId, int $amount): void
    {
        if ($amount > 0) {
            $debits[] = $this->line($accountId, $organizationId, $cashAccountId, $amount, 0);
        } elseif ($amount < 0) {
            $credits[] = $this->line($accountId, $organizationId, $cashAccountId, 0, -$amount);
        }
    }

    /**
     * Muat COA & pemetaan yayasan (sekali per potongan).
     */
    private function load(string $tenantId): void
    {
        $this->accounts->ensureDefaults($tenantId);
        $this->keyed = $this->accounts->keyed($tenantId);
        $locked = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->value('locked_through');
        $this->lockedThrough = $locked === null ? null : substr((string) $locked, 0, 10);

        $this->organizationNames = [];

        foreach (DB::table('organizations')->where('tenant_id', $tenantId)->get(['id', 'name']) as $organization) {
            $this->organizationNames[(string) $organization->id] = (string) $organization->name;
        }

        $this->cashAccounts = [];

        foreach (DB::table('fn_cash_accounts')->where('tenant_id', $tenantId)->get(['id', 'organization_id', 'kind', 'name', 'ledger_account_id']) as $row) {
            $kind = (string) $row->kind;
            $this->cashAccounts[(string) $row->id] = [
                'ledger_account_id' => match (true) {
                    $kind === CashAccountKind::Custody->value => $this->keyed[ChartOfAccounts::KEY_SAVINGS_CASH],
                    $row->ledger_account_id !== null => (string) $row->ledger_account_id,
                    $kind === CashAccountKind::Bank->value => $this->keyed[ChartOfAccounts::KEY_BANK],
                    default => $this->keyed[ChartOfAccounts::KEY_CASH],
                },
                'organization_id' => $row->organization_id === null ? null : (string) $row->organization_id,
                'kind' => $kind,
                'name' => (string) $row->name,
            ];
        }

        $this->categories = [];

        foreach (DB::table('fn_cash_categories')->where('tenant_id', $tenantId)->get(['id', 'direction', 'ledger_account_id']) as $row) {
            $this->categories[(string) $row->id] = [
                'ledger_account_id' => $row->ledger_account_id === null ? null : (string) $row->ledger_account_id,
                'direction' => (string) $row->direction,
            ];
        }

        $this->feeTypes = [];

        foreach (DB::table('fn_fee_types')->where('tenant_id', $tenantId)->get(['id', 'ledger_account_id']) as $row) {
            $this->feeTypes[(string) $row->id] = $row->ledger_account_id === null ? null : (string) $row->ledger_account_id;
        }
    }

    private function frozen(?string $date): bool
    {
        return $date !== null && $this->lockedThrough !== null && $date <= $this->lockedThrough;
    }

    private function startDate(string $tenantId): ?string
    {
        $value = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->value('start_date');

        return $value === null ? null : substr((string) $value, 0, 10);
    }

    /**
     * @return Line
     */
    private function line(string $accountId, ?string $organizationId, ?string $cashAccountId, int $debit, int $credit): array
    {
        return ['account_id' => $accountId, 'organization_id' => $organizationId, 'cash_account_id' => $cashAccountId, 'debit' => $debit, 'credit' => $credit];
    }

    /**
     * @param  array{organization_id: string|null, name: string}  $account
     */
    private function accountLabel(array $account): string
    {
        $owner = $account['organization_id'] === null ? 'Kas Yayasan' : ($this->organizationNames[$account['organization_id']] ?? 'lembaga');

        return "{$account['name']} ({$owner})";
    }

    /**
     * Alasan batal jurnal: "<awalan>: <alasan sumber>", atau alasan sumber apa adanya bila sudah lengkap ($prefixed false).
     */
    private function reason(string $prefix, mixed $reason, bool $prefixed = true): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            return $prefix;
        }

        return mb_substr($prefixed ? "{$prefix}: {$reason}" : $reason, 0, 200);
    }

    private function text(string $text): string
    {
        return mb_substr(trim($text), 0, 250);
    }
}
