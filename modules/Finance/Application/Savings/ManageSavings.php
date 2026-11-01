<?php

namespace Modules\Finance\Application\Savings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Cash\ManageCashAccounts;
use Modules\Finance\Application\Cash\ManageCashCategories;
use Modules\Finance\Application\Cash\ManageCashEntries;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Domain\Payment\PaymentStatus;
use Modules\Finance\Domain\Savings\SavingsEntryKind;
use stdClass;

/**
 * Setoran, penarikan & pembatalan tabungan siswa (PRD-005 §4, TB-1). Hak akses (finance.savings.manage di lembaga
 * pendaftaran) dicek pemanggil.
 *
 * Aturan [PRD-005, disetujui owner 2026-11-01]:
 * - rekening tabungan per siswa per lembaga, dibuat otomatis saat setoran pertama; siswa yang sudah keluar tidak bisa
 *   menabung lagi, tetapi masih bisa menarik saldonya;
 * - jumlah Rp 1 s.d. Rp 100.000.000; tanggal tidak di masa depan dan paling lama 1 tahun lalu (sama dengan pembayaran);
 * - penarikan bebas selama saldo cukup (OD-TB-02), nama pengambil wajib; saldo tidak pernah minus;
 * - slip `TAB-KODE-LEMBAGA/TAHUN/000001`, urut per lembaga per tahun tanggal transaksi, tidak lompat/dobel (kunci
 *   per lembaga × tahun);
 * - setiap transaksi tercermin di buku kas lembaga, rekening Titipan tabungan (OD-TB-03): setoran MASUK, penarikan
 *   KELUAR; pembatalan ikut membatalkan baris kasnya;
 * - batal: alasan wajib; batal setoran ditolak bila saldo sekarang kurang; transaksi bayar tagihan (TB-3) hanya batal
 *   lewat kuitansinya.
 */
final class ManageSavings
{
    public const MAX_AMOUNT = 100_000_000;

    public const NOTE_MAX_LENGTH = 200;

    public const TAKEN_BY_MAX_LENGTH = 100;

    public const REASON_MAX_LENGTH = 200;

    public function __construct(
        private readonly EnrolledStudents $students,
        private readonly AuditLogger $audit,
        private readonly ManageCashAccounts $cashAccounts,
        private readonly ManageCashCategories $cashCategories,
    ) {}

    /**
     * @return string id transaksi tabungan
     *
     * @throws FinanceException
     */
    public function deposit(string $tenantId, string $organizationId, string $enrollmentId, mixed $amount, string $date, string $method, ?string $note, ?string $membershipId): string
    {
        return $this->record($tenantId, $organizationId, $enrollmentId, SavingsEntryKind::Deposit, $amount, $date, $method, null, $note, $membershipId);
    }

    /**
     * @return string id transaksi tabungan
     *
     * @throws FinanceException
     */
    public function withdraw(string $tenantId, string $organizationId, string $enrollmentId, mixed $amount, string $date, string $method, string $takenBy, ?string $note, ?string $membershipId): string
    {
        return $this->record($tenantId, $organizationId, $enrollmentId, SavingsEntryKind::Withdrawal, $amount, $date, $method, $takenBy, $note, $membershipId);
    }

    /**
     * Lembaga pemilik transaksi tabungan (untuk cek hak akses), atau null bila tidak ada di yayasan ini.
     */
    public function organizationOf(string $tenantId, string $entryId): ?string
    {
        if (! Str::isUuid($entryId)) {
            return null;
        }

        $organizationId = DB::table('fn_savings_entries')->where('tenant_id', $tenantId)->where('id', $entryId)->value('organization_id');

        return $organizationId === null ? null : (string) $organizationId;
    }

    /**
     * @throws FinanceException
     */
    public function void(string $tenantId, string $entryId, string $reason, ?string $membershipId): void
    {
        $reason = self::clean($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', 'Alasan pembatalan wajib diisi, maksimal '.self::REASON_MAX_LENGTH.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $entryId, $reason, $membershipId): void {
            $entry = Str::isUuid($entryId)
                ? DB::table('fn_savings_entries')->where('tenant_id', $tenantId)->where('id', $entryId)->lockForUpdate()->first()
                : null;

            if ($entry === null) {
                throw FinanceException::make('form', 'Transaksi tabungan tidak ditemukan di yayasan ini.');
            }

            if ((string) $entry->status === PaymentStatus::Void->value) {
                throw FinanceException::make('form', 'Transaksi ini sudah dibatalkan.');
            }

            $kind = SavingsEntryKind::from((string) $entry->kind);

            if ($kind === SavingsEntryKind::InvoicePayment) {
                throw FinanceException::make('form', 'Pembayaran tagihan dari tabungan dibatalkan lewat kuitansinya.');
            }

            $account = $this->lockAccount((string) $entry->savings_account_id);
            $amount = (int) $entry->amount;
            $balance = (int) $account->balance + ($kind === SavingsEntryKind::Deposit ? -$amount : $amount);

            if ($balance < 0) {
                throw FinanceException::make('form', 'Pembatalan setoran ditolak: saldo tabungan sekarang ('.Rupiah::format((int) $account->balance).') kurang dari jumlah setoran. Batalkan dulu penarikan sesudahnya.');
            }

            $now = now();

            DB::table('fn_savings_entries')->where('id', $entry->id)->update([
                'status' => PaymentStatus::Void->value,
                'void_reason' => $reason,
                'voided_at' => $now,
                'voided_by_membership_id' => $membershipId,
                'updated_at' => $now,
            ]);

            DB::table('fn_savings_accounts')->where('id', $account->id)->update(['balance' => $balance, 'updated_at' => $now]);

            // Baris buku kas di rekening Titipan tabungan ikut batal.
            DB::table('fn_cash_entries')
                ->where('source', CashEntrySource::Savings->value)
                ->where('source_id', $entry->id)
                ->update([
                    'status' => PaymentStatus::Void->value,
                    'void_reason' => mb_substr('Transaksi tabungan dibatalkan: '.$reason, 0, ManageCashEntries::REASON_MAX_LENGTH),
                    'voided_at' => $now,
                    'voided_by_membership_id' => $membershipId,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                FinanceAuditAction::SAVINGS_ENTRY_VOIDED,
                FinanceAuditAction::SUBJECT_SAVINGS_ENTRY,
                (string) $entry->id,
                ['slip' => (string) $entry->slip_number, 'jenis' => $kind->value, 'jumlah' => $amount, 'alasan' => $reason],
                organizationId: (string) $entry->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    private function record(string $tenantId, string $organizationId, string $enrollmentId, SavingsEntryKind $kind, mixed $amount, string $date, string $method, ?string $takenBy, ?string $note, ?string $membershipId): string
    {
        $value = Rupiah::parse($amount, 'amount', $kind === SavingsEntryKind::Deposit ? 'jumlah setoran' : 'jumlah penarikan', 1, self::MAX_AMOUNT);
        $methodCase = PaymentMethod::tryFrom($method) ?? throw FinanceException::make('method', 'Pilih cara: tunai atau transfer.');
        $day = $this->entryDate($date);
        $note = self::optional($note, 'note', 'Catatan', self::NOTE_MAX_LENGTH);

        if ($kind === SavingsEntryKind::Withdrawal) {
            $takenBy = self::clean((string) $takenBy);

            if ($takenBy === '' || mb_strlen($takenBy) > self::TAKEN_BY_MAX_LENGTH) {
                throw FinanceException::make('taken_by', 'Isi nama yang mengambil uang (mis. "Ibu Siti (ibu)"), maksimal '.self::TAKEN_BY_MAX_LENGTH.' karakter.');
            }
        } else {
            $takenBy = null;
        }

        $student = $this->students->describe($tenantId, [$enrollmentId])[$enrollmentId] ?? null;

        if ($student === null || $student['organization_id'] !== $organizationId) {
            throw FinanceException::make('enrollment_id', 'Pilih siswa lembaga ini.');
        }

        if ($kind === SavingsEntryKind::Deposit && ! $student['active']) {
            throw FinanceException::make('enrollment_id', 'Siswa ini sudah keluar dari lembaga, jadi tidak bisa menabung lagi. Saldonya masih bisa ditarik.');
        }

        return DB::transaction(function () use ($tenantId, $organizationId, $enrollmentId, $student, $kind, $value, $methodCase, $day, $takenBy, $note, $membershipId): string {
            $accountId = $this->accountId($tenantId, $organizationId, $student['student_id'], $kind === SavingsEntryKind::Deposit);

            if ($accountId === null) {
                throw FinanceException::make('amount', 'Siswa ini belum punya tabungan di lembaga ini.');
            }

            $account = $this->lockAccount($accountId);

            $balance = (int) $account->balance + ($kind === SavingsEntryKind::Deposit ? $value : -$value);

            if ($balance < 0) {
                throw FinanceException::make('amount', 'Saldo tabungan tidak cukup. Saldo sekarang '.Rupiah::format((int) $account->balance).'.');
            }

            // Kategori & rekening Titipan disiapkan sebelum nomor slip dikunci (urutan kunci tetap: rekening → slip).
            $this->cashCategories->ensureDefaults($tenantId);
            $custodyId = $this->cashAccounts->ensureCustodyAccount($tenantId, $organizationId);
            $categoryId = (string) DB::table('fn_cash_categories')
                ->where('tenant_id', $tenantId)
                ->where('code', $kind === SavingsEntryKind::Deposit ? ManageCashCategories::SYSTEM_SAVINGS_DEPOSIT : ManageCashCategories::SYSTEM_SAVINGS_WITHDRAWAL)
                ->value('id');

            [$year, $sequence, $number] = $this->nextSlip($organizationId, $day);
            $entryId = (string) Str::uuid7();
            $now = now();

            DB::table('fn_savings_entries')->insert([
                'id' => $entryId,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'savings_account_id' => $accountId,
                'enrollment_id' => $enrollmentId,
                'entry_date' => $day->toDateString(),
                'kind' => $kind->value,
                'method' => $methodCase->value,
                'amount' => $value,
                'balance_after' => $balance,
                'slip_number' => $number,
                'slip_year' => $year,
                'slip_sequence' => $sequence,
                'taken_by' => $takenBy,
                'note' => $note,
                'status' => PaymentStatus::Valid->value,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('fn_savings_accounts')->where('id', $accountId)->update(['balance' => $balance, 'updated_at' => $now]);

            DB::table('fn_cash_entries')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'account_id' => $custodyId,
                'category_id' => $categoryId,
                'entry_date' => $day->toDateString(),
                'direction' => $kind === SavingsEntryKind::Deposit ? CashDirection::In->value : CashDirection::Out->value,
                'amount' => $value,
                'description' => mb_substr($kind->label().' tabungan — '.$student['name']." ({$number})", 0, ManageCashEntries::DESCRIPTION_MAX_LENGTH),
                'source' => CashEntrySource::Savings->value,
                'source_id' => $entryId,
                'status' => PaymentStatus::Valid->value,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                $kind === SavingsEntryKind::Deposit ? FinanceAuditAction::SAVINGS_DEPOSITED : FinanceAuditAction::SAVINGS_WITHDRAWN,
                FinanceAuditAction::SUBJECT_SAVINGS_ENTRY,
                $entryId,
                array_filter([
                    'slip' => $number,
                    'jumlah' => $value,
                    'cara' => $methodCase->value,
                    'tanggal' => $day->toDateString(),
                    'diambil_oleh' => $takenBy,
                ], fn (mixed $item): bool => $item !== null),
                organizationId: $organizationId,
                tenantId: $tenantId,
            );

            return $entryId;
        });
    }

    /**
     * Id rekening tabungan siswa di lembaga; dibuat bila belum ada dan $create (setoran). Aman bersamaan (indeks unik).
     */
    private function accountId(string $tenantId, string $organizationId, string $studentId, bool $create): ?string
    {
        $find = fn (): mixed => DB::table('fn_savings_accounts')
            ->where('organization_id', $organizationId)
            ->where('student_id', $studentId)
            ->value('id');

        $id = $find();

        if ($id === null && $create) {
            DB::table('fn_savings_accounts')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'student_id' => $studentId,
                'balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $id = $find();
        }

        return $id === null ? null : (string) $id;
    }

    private function lockAccount(string $accountId): stdClass
    {
        $account = DB::table('fn_savings_accounts')->where('id', $accountId)->lockForUpdate()->first(['id', 'balance']);

        if ($account === null) {
            throw FinanceException::make('form', 'Rekening tabungan tidak ditemukan.');
        }

        return $account;
    }

    /**
     * @throws FinanceException
     */
    private function entryDate(string $value): CarbonImmutable
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? CarbonImmutable::parse($value, BusinessClock::timezone())
            : null;
        $today = BusinessClock::today();

        if ($date === null || $date > $today || $date < $today->subYear()) {
            throw FinanceException::make('entry_date', 'Tanggal tidak boleh di masa depan atau lebih dari 1 tahun lalu.');
        }

        return $date;
    }

    /**
     * Nomor slip berikutnya untuk lembaga × tahun tanggal transaksi. Dipanggil di dalam transaksi.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function nextSlip(string $organizationId, CarbonImmutable $date): array
    {
        $year = $date->year;
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance-savings-slip:{$organizationId}:{$year}"]);

        $sequence = (int) DB::table('fn_savings_entries')->where('organization_id', $organizationId)->where('slip_year', $year)->max('slip_sequence') + 1;
        $code = (string) DB::table('organizations')->where('id', $organizationId)->value('code');

        return [$year, $sequence, sprintf('TAB-%s/%d/%06d', $code, $year, $sequence)];
    }

    /**
     * @throws FinanceException
     */
    private static function optional(?string $value, string $field, string $label, int $max): ?string
    {
        $value = $value === null ? '' : self::clean($value);

        if (mb_strlen($value) > $max) {
            throw FinanceException::make($field, "{$label} maksimal {$max} karakter.");
        }

        return $value === '' ? null : $value;
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
