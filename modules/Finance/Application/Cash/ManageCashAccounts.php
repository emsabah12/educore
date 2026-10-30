<?php

namespace Modules\Finance\Application\Cash;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Cash\CashAccountKind;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;

/**
 * Rekening kas (PRD-004 §3, KS-1). Satu kas = satu LEMBAGA atau kas Yayasan ($organizationId null). Hak akses
 * (finance.cash.manage di kas itu) dicek pemanggil.
 *
 * Aturan [ASUMSI KS-1]:
 * - Kas tunai dibuat otomatis (saldo awal Rp 0 per hari pertama dibuka); namanya tetap "Kas tunai".
 * - Rekening bank: nama tampilan 1–100, nama bank 1–60, nomor rekening opsional (angka, spasi, ".", "-", ≤ 40).
 * - Saldo awal Rp 0 s.d. Rp 999.999.999.999 per tanggal (tidak di masa depan, ≤ 10 tahun lalu). Saldo awal &
 *   tanggalnya hanya bisa diubah selama rekening belum punya transaksi (agar buku kas lama tidak bergeser).
 */
final class ManageCashAccounts
{
    public const NAME_MAX_LENGTH = 100;

    public const BANK_MAX_LENGTH = 60;

    public const MAX_AMOUNT = 999_999_999_999;

    public const CASH_NAME = 'Kas tunai';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pastikan kas punya rekening Kas tunai (aman diulang & bersamaan: indeks unik parsial).
     */
    public function ensureCashAccount(string $tenantId, ?string $organizationId): void
    {
        $exists = DB::table('fn_cash_accounts')
            ->where('tenant_id', $tenantId)
            ->where('kind', CashAccountKind::Cash->value)
            ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('fn_cash_accounts')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'kind' => CashAccountKind::Cash->value,
            'name' => self::CASH_NAME,
            'opening_balance' => 0,
            'opening_date' => BusinessClock::todayString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @throws FinanceException
     */
    public function createBank(string $tenantId, ?string $organizationId, string $name, string $bankName, ?string $accountNumber, mixed $opening, string $openingDate, ?string $membershipId): string
    {
        $fields = $this->bankFields($name, $bankName, $accountNumber);
        $amount = Rupiah::parse($opening, 'opening_balance', 'saldo awal', 0, self::MAX_AMOUNT);
        $date = $this->openingDate($openingDate);
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $organizationId, $fields, $amount, $date, $membershipId, $id): void {
            DB::table('fn_cash_accounts')->insert($fields + [
                'id' => $id,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'kind' => CashAccountKind::Bank->value,
                'opening_balance' => $amount,
                'opening_date' => $date,
                'created_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::CASH_ACCOUNT_CREATED,
                FinanceAuditAction::SUBJECT_CASH_ACCOUNT,
                $id,
                ['nama' => $fields['name'], 'bank' => $fields['bank_name'], 'saldo_awal' => $amount, 'tanggal_saldo_awal' => $date],
                organizationId: $organizationId,
                tenantId: $tenantId,
            );
        });

        return $id;
    }

    /**
     * Ubah rekening. Kas tunai: hanya saldo awal & tanggalnya.
     *
     * @throws FinanceException
     */
    public function update(string $tenantId, string $accountId, ?string $name, ?string $bankName, ?string $accountNumber, mixed $opening, string $openingDate): void
    {
        $amount = Rupiah::parse($opening, 'opening_balance', 'saldo awal', 0, self::MAX_AMOUNT);
        $date = $this->openingDate($openingDate);

        DB::transaction(function () use ($tenantId, $accountId, $name, $bankName, $accountNumber, $amount, $date): void {
            $account = Str::isUuid($accountId)
                ? DB::table('fn_cash_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->lockForUpdate()->first()
                : null;

            if ($account === null) {
                throw FinanceException::make('form', 'Rekening tidak ditemukan.');
            }

            $values = [];

            if ((string) $account->kind === CashAccountKind::Bank->value) {
                $values = $this->bankFields($name ?? '', $bankName ?? '', $accountNumber);
            }

            $openingChanged = (int) $account->opening_balance !== $amount || substr((string) $account->opening_date, 0, 10) !== $date;

            if ($openingChanged) {
                if (DB::table('fn_cash_entries')->where('account_id', $accountId)->exists()) {
                    throw FinanceException::make('opening_balance', 'Saldo awal tidak bisa diubah karena rekening ini sudah punya transaksi.');
                }

                $values += ['opening_balance' => $amount, 'opening_date' => $date];
            }

            $changes = [];

            foreach ($values as $key => $value) {
                $old = $key === 'opening_date' ? substr((string) $account->{$key}, 0, 10) : $account->{$key};

                if ($old != $value) {
                    $changes[$key] = [$old, $value];
                }
            }

            if ($changes === []) {
                return;
            }

            DB::table('fn_cash_accounts')->where('id', $accountId)->update($values + ['updated_at' => now()]);

            $this->audit->record(
                FinanceAuditAction::CASH_ACCOUNT_UPDATED,
                FinanceAuditAction::SUBJECT_CASH_ACCOUNT,
                $accountId,
                ['rekening' => (string) $account->name] + $changes,
                organizationId: $account->organization_id === null ? null : (string) $account->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Kas pemilik rekening (untuk cek hak akses), atau false bila rekening tidak ada di yayasan ini.
     *
     * @return array{organization_id: string|null}|false
     */
    public function ownerOf(string $tenantId, string $accountId): array|false
    {
        $account = Str::isUuid($accountId)
            ? DB::table('fn_cash_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->first(['organization_id'])
            : null;

        return $account === null ? false : ['organization_id' => $account->organization_id === null ? null : (string) $account->organization_id];
    }

    /**
     * @return array{name: string, bank_name: string, account_number: string|null}
     *
     * @throws FinanceException
     */
    private function bankFields(string $name, string $bankName, ?string $accountNumber): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $bankName = trim((string) preg_replace('/\s+/u', ' ', $bankName));
        $accountNumber = trim((string) $accountNumber);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw FinanceException::make('name', 'Nama rekening wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        if ($bankName === '' || mb_strlen($bankName) > self::BANK_MAX_LENGTH) {
            throw FinanceException::make('bank_name', 'Nama bank wajib diisi, maksimal '.self::BANK_MAX_LENGTH.' karakter.');
        }

        if ($accountNumber !== '' && preg_match('/^[0-9][0-9 .-]{0,39}$/', $accountNumber) !== 1) {
            throw FinanceException::make('account_number', 'Nomor rekening hanya berisi angka, spasi, titik, atau tanda "-", maksimal 40 karakter.');
        }

        return ['name' => $name, 'bank_name' => $bankName, 'account_number' => $accountNumber === '' ? null : $accountNumber];
    }

    /**
     * @throws FinanceException
     */
    private function openingDate(string $value): string
    {
        $today = BusinessClock::today();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? CarbonImmutable::parse($value, BusinessClock::timezone())
            : null;

        if ($date === null || $date > $today || $date < $today->subYears(10)) {
            throw FinanceException::make('opening_date', 'Tanggal saldo awal tidak boleh di masa depan atau lebih dari 10 tahun lalu.');
        }

        return $date->toDateString();
    }
}
