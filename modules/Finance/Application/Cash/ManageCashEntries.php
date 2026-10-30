<?php

namespace Modules\Finance\Application\Cash;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Payment\PaymentStatus;
use stdClass;

/**
 * Transaksi kas manual (PRD-004 §3–§4, KS-1): catat pemasukan lain (BOS, donasi, …) langsung sah (OD-KS-07) dan
 * batalkan transaksi manual. Pengeluaran TIDAK dicatat di sini — wajib lewat pengajuan & persetujuan (KS-3).
 * Hak akses (finance.cash.manage di kas itu) dicek pemanggil.
 *
 * Aturan [ASUMSI KS-1]: tanggal tidak di masa depan dan tidak sebelum tanggal saldo awal rekening; kategori MASUK
 * aktif bukan kategori sistem; jumlah Rp 1 s.d. Rp 999.999.999.999; uraian 3–200 karakter. Batal: alasan 1–200;
 * ditolak bila membuat saldo rekening minus (OD-KS-08).
 */
final class ManageCashEntries
{
    public const DESCRIPTION_MAX_LENGTH = 200;

    public const REASON_MAX_LENGTH = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CashBalances $balances,
    ) {}

    /**
     * @throws FinanceException
     */
    public function recordIncome(string $tenantId, ?string $organizationId, string $accountId, string $date, string $categoryId, mixed $amount, string $description, ?string $membershipId): string
    {
        $value = Rupiah::parse($amount, 'amount', 'jumlah', 1, ManageCashAccounts::MAX_AMOUNT);
        $description = trim((string) preg_replace('/\s+/u', ' ', $description));

        if (mb_strlen($description) < 3 || mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw FinanceException::make('description', 'Uraian wajib diisi, 3–'.self::DESCRIPTION_MAX_LENGTH.' karakter.');
        }

        $day = $this->date($date);
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $organizationId, $accountId, $day, $categoryId, $value, $description, $membershipId, $id): void {
            $account = $this->lockAccount($tenantId, $organizationId, $accountId);

            if ($day < substr((string) $account->opening_date, 0, 10)) {
                throw FinanceException::make('entry_date', 'Tanggal tidak boleh sebelum tanggal saldo awal rekening ('.substr((string) $account->opening_date, 0, 10).').');
            }

            $category = Str::isUuid($categoryId)
                ? DB::table('fn_cash_categories')->where('tenant_id', $tenantId)->where('id', $categoryId)->first()
                : null;

            if ($category === null || (string) $category->direction !== CashDirection::In->value || ! (bool) $category->is_active || (bool) $category->is_system) {
                throw FinanceException::make('category_id', 'Pilih kategori pemasukan yang aktif.');
            }

            DB::table('fn_cash_entries')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'entry_date' => $day,
                'direction' => CashDirection::In->value,
                'amount' => $value,
                'description' => $description,
                'source' => CashEntrySource::Manual->value,
                'status' => PaymentStatus::Valid->value,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::CASH_ENTRY_RECORDED,
                FinanceAuditAction::SUBJECT_CASH_ENTRY,
                $id,
                ['rekening' => (string) $account->name, 'jenis' => CashDirection::In->value, 'kategori' => (string) $category->name, 'jumlah' => $value, 'tanggal' => $day],
                organizationId: $organizationId,
                tenantId: $tenantId,
            );
        });

        return $id;
    }

    /**
     * Batalkan transaksi MANUAL. Transaksi dari kasir/pengajuan/mutasi dibatalkan dari asalnya.
     *
     * @throws FinanceException
     */
    public function void(string $tenantId, string $entryId, string $reason, ?string $membershipId): void
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', 'Alasan pembatalan wajib diisi, maksimal '.self::REASON_MAX_LENGTH.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $entryId, $reason, $membershipId): void {
            $entry = Str::isUuid($entryId)
                ? DB::table('fn_cash_entries')->where('tenant_id', $tenantId)->where('id', $entryId)->first()
                : null;

            if ($entry === null) {
                throw FinanceException::make('form', 'Transaksi tidak ditemukan.');
            }

            // Kunci rekening dulu (urutan kunci sama dengan pencatatan), lalu baca ulang transaksinya.
            $account = DB::table('fn_cash_accounts')->where('id', $entry->account_id)->lockForUpdate()->first();
            $entry = DB::table('fn_cash_entries')->where('id', $entryId)->lockForUpdate()->first();

            if ($account === null || $entry === null) {
                throw FinanceException::make('form', 'Transaksi tidak ditemukan.');
            }

            if ((string) $entry->status !== PaymentStatus::Valid->value) {
                throw FinanceException::make('form', 'Transaksi ini sudah dibatalkan.');
            }

            if ((string) $entry->source !== CashEntrySource::Manual->value) {
                throw FinanceException::make('form', 'Transaksi ini berasal dari proses lain dan dibatalkan dari asalnya.');
            }

            $amount = (int) $entry->amount;

            if ((string) $entry->direction === CashDirection::In->value && $this->balances->balance((string) $account->id) - $amount < 0) {
                throw FinanceException::make('form', 'Pembatalan ditolak: saldo '.((string) $account->name).' akan menjadi minus.');
            }

            DB::table('fn_cash_entries')->where('id', $entryId)->update([
                'status' => PaymentStatus::Void->value,
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by_membership_id' => $membershipId,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::CASH_ENTRY_VOIDED,
                FinanceAuditAction::SUBJECT_CASH_ENTRY,
                $entryId,
                ['rekening' => (string) $account->name, 'jumlah' => $amount, 'tanggal' => substr((string) $entry->entry_date, 0, 10), 'alasan' => $reason],
                organizationId: $entry->organization_id === null ? null : (string) $entry->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Kas pemilik transaksi (untuk cek hak akses), atau false bila tidak ada di yayasan ini.
     *
     * @return array{organization_id: string|null}|false
     */
    public function ownerOf(string $tenantId, string $entryId): array|false
    {
        $entry = Str::isUuid($entryId)
            ? DB::table('fn_cash_entries')->where('tenant_id', $tenantId)->where('id', $entryId)->first(['organization_id'])
            : null;

        return $entry === null ? false : ['organization_id' => $entry->organization_id === null ? null : (string) $entry->organization_id];
    }

    /**
     * Rekening aktif milik kas itu, dikunci sampai transaksi selesai.
     *
     * @throws FinanceException
     */
    private function lockAccount(string $tenantId, ?string $organizationId, string $accountId): stdClass
    {
        $account = Str::isUuid($accountId)
            ? DB::table('fn_cash_accounts')
                ->where('tenant_id', $tenantId)
                ->where('id', $accountId)
                ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
                ->lockForUpdate()
                ->first()
            : null;

        if ($account === null || ! (bool) $account->is_active) {
            throw FinanceException::make('account_id', 'Pilih rekening kas ini yang aktif.');
        }

        return $account;
    }

    /**
     * @throws FinanceException
     */
    private function date(string $value): string
    {
        $today = BusinessClock::today();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? CarbonImmutable::parse($value, BusinessClock::timezone())
            : null;

        if ($date === null || $date > $today) {
            throw FinanceException::make('entry_date', 'Isi tanggal yang benar dan tidak di masa depan.');
        }

        return $date->toDateString();
    }
}
