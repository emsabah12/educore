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
 * Mutasi kas (PRD-004 §3, OD-KS-07/08, KS-4): langsung sah, tanpa persetujuan. Hak akses (finance.cash.manage di kas
 * ASAL) dicek pemanggil; arah yang diizinkan dicek di sini.
 *
 * Aturan [ASUMSI KS-4]:
 * - arah: antar-rekening dalam satu kas; kas lembaga → kas Yayasan (setoran); kas Yayasan → kas lembaga (subsidi).
 *   Lembaga → lembaga lain tidak diizinkan (lewat Yayasan);
 * - rekening asal & tujuan aktif, berbeda; tanggal tidak di masa depan dan tidak sebelum tanggal saldo awal kedua
 *   rekening; jumlah Rp 1–999.999.999.999 dan tidak melebihi saldo rekening asal; uraian 3–200;
 * - batal: alasan 1–200; ditolak bila saldo rekening tujuan menjadi minus.
 */
final class ManageCashTransfers
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CashBalances $balances,
        private readonly ManageCashCategories $categories,
    ) {}

    /**
     * @throws FinanceException
     */
    public function record(string $tenantId, string $fromAccountId, string $toAccountId, string $date, mixed $amount, string $description, ?string $membershipId): string
    {
        $value = Rupiah::parse($amount, 'amount', 'jumlah', 1, ManageCashAccounts::MAX_AMOUNT);
        $description = trim((string) preg_replace('/\s+/u', ' ', $description));

        if (mb_strlen($description) < 3 || mb_strlen($description) > ManageCashEntries::DESCRIPTION_MAX_LENGTH) {
            throw FinanceException::make('description', 'Uraian wajib diisi, 3–'.ManageCashEntries::DESCRIPTION_MAX_LENGTH.' karakter.');
        }

        $day = $this->date($date);

        if (! Str::isUuid($fromAccountId) || ! Str::isUuid($toAccountId) || $fromAccountId === $toAccountId) {
            throw FinanceException::make('to_account_id', 'Pilih rekening tujuan yang berbeda dari rekening asal.');
        }

        $inCategory = $this->categories->systemId($tenantId, ManageCashCategories::SYSTEM_TRANSFER_IN);
        $outCategory = $this->categories->systemId($tenantId, ManageCashCategories::SYSTEM_TRANSFER_OUT);
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $fromAccountId, $toAccountId, $day, $value, $description, $membershipId, $id, $inCategory, $outCategory): void {
            // Kunci kedua rekening dalam urutan id agar dua mutasi berlawanan tidak saling menunggu.
            $locked = [];

            foreach (DB::table('fn_cash_accounts')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', [$fromAccountId, $toAccountId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get() as $account) {
                $locked[(string) $account->id] = $account;
            }

            $from = $locked[$fromAccountId] ?? null;
            $to = $locked[$toAccountId] ?? null;

            if ($from === null || ! (bool) $from->is_active) {
                throw FinanceException::make('account_id', 'Pilih rekening asal yang aktif.');
            }

            if ($to === null || ! (bool) $to->is_active) {
                throw FinanceException::make('to_account_id', 'Pilih rekening tujuan yang aktif.');
            }

            $fromOrg = $from->organization_id === null ? null : (string) $from->organization_id;
            $toOrg = $to->organization_id === null ? null : (string) $to->organization_id;

            if ($fromOrg !== $toOrg && $fromOrg !== null && $toOrg !== null) {
                throw FinanceException::make('to_account_id', 'Mutasi antar-lembaga harus lewat kas Yayasan.');
            }

            foreach ([$from, $to] as $account) {
                if ($day < substr((string) $account->opening_date, 0, 10)) {
                    throw FinanceException::make('entry_date', 'Tanggal tidak boleh sebelum tanggal saldo awal '.((string) $account->name).' ('.substr((string) $account->opening_date, 0, 10).').');
                }
            }

            $balance = $this->balances->balance($fromAccountId);

            if ($balance < $value) {
                throw FinanceException::make('amount', 'Saldo '.((string) $from->name).' tidak cukup ('.Rupiah::format($balance).').');
            }

            DB::table('fn_cash_transfers')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'transfer_date' => $day,
                'amount' => $value,
                'description' => $description,
                'status' => PaymentStatus::Valid->value,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $base = [
                'tenant_id' => $tenantId,
                'entry_date' => $day,
                'amount' => $value,
                'source' => CashEntrySource::Transfer->value,
                'source_id' => $id,
                'status' => PaymentStatus::Valid->value,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('fn_cash_entries')->insert([
                $base + [
                    'id' => (string) Str::uuid7(), 'organization_id' => $fromOrg, 'account_id' => $fromAccountId, 'category_id' => $outCategory,
                    'direction' => CashDirection::Out->value, 'description' => $this->label($description, 'ke', $to),
                ],
                $base + [
                    'id' => (string) Str::uuid7(), 'organization_id' => $toOrg, 'account_id' => $toAccountId, 'category_id' => $inCategory,
                    'direction' => CashDirection::In->value, 'description' => $this->label($description, 'dari', $from),
                ],
            ]);

            $this->audit->record(
                FinanceAuditAction::CASH_TRANSFER_RECORDED,
                FinanceAuditAction::SUBJECT_CASH_TRANSFER,
                $id,
                ['dari' => (string) $from->name, 'ke' => (string) $to->name, 'jumlah' => $value, 'tanggal' => $day],
                organizationId: $fromOrg,
                tenantId: $tenantId,
            );
        });

        return $id;
    }

    /**
     * @throws FinanceException
     */
    public function void(string $tenantId, string $transferId, string $reason, ?string $membershipId): void
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if ($reason === '' || mb_strlen($reason) > ManageCashEntries::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', 'Alasan pembatalan wajib diisi, maksimal '.ManageCashEntries::REASON_MAX_LENGTH.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $transferId, $reason, $membershipId): void {
            $transfer = $this->find($tenantId, $transferId);

            DB::table('fn_cash_accounts')->whereIn('id', [$transfer->from_account_id, $transfer->to_account_id])->orderBy('id')->lockForUpdate()->get(['id']);
            $transfer = DB::table('fn_cash_transfers')->where('id', $transferId)->lockForUpdate()->first();

            if ($transfer === null || (string) $transfer->status !== PaymentStatus::Valid->value) {
                throw FinanceException::make('form', 'Mutasi ini sudah dibatalkan.');
            }

            $to = DB::table('fn_cash_accounts')->where('id', $transfer->to_account_id)->first(['id', 'name']);

            if ($to !== null && $this->balances->balance((string) $to->id) - (int) $transfer->amount < 0) {
                throw FinanceException::make('form', 'Pembatalan ditolak: saldo '.((string) $to->name).' akan menjadi minus.');
            }

            $void = ['status' => PaymentStatus::Void->value, 'void_reason' => $reason, 'voided_at' => now(), 'voided_by_membership_id' => $membershipId, 'updated_at' => now()];

            DB::table('fn_cash_transfers')->where('id', $transferId)->update($void);
            DB::table('fn_cash_entries')->where('source', CashEntrySource::Transfer->value)->where('source_id', $transferId)->update($void);

            $from = DB::table('fn_cash_accounts')->where('id', $transfer->from_account_id)->first(['name', 'organization_id']);

            $this->audit->record(
                FinanceAuditAction::CASH_TRANSFER_VOIDED,
                FinanceAuditAction::SUBJECT_CASH_TRANSFER,
                $transferId,
                ['dari' => $from === null ? null : (string) $from->name, 'ke' => $to === null ? null : (string) $to->name, 'jumlah' => (int) $transfer->amount, 'alasan' => $reason],
                organizationId: $from === null || $from->organization_id === null ? null : (string) $from->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Kas asal mutasi (untuk cek hak akses), atau false bila tidak ada di yayasan ini.
     *
     * @return array{organization_id: string|null}|false
     */
    public function ownerOf(string $tenantId, string $transferId): array|false
    {
        $row = Str::isUuid($transferId)
            ? DB::table('fn_cash_transfers as t')
                ->join('fn_cash_accounts as a', 'a.id', '=', 't.from_account_id')
                ->where('t.tenant_id', $tenantId)
                ->where('t.id', $transferId)
                ->first(['a.organization_id'])
            : null;

        return $row === null ? false : ['organization_id' => $row->organization_id === null ? null : (string) $row->organization_id];
    }

    /**
     * @throws FinanceException
     */
    private function find(string $tenantId, string $transferId): stdClass
    {
        $transfer = Str::isUuid($transferId)
            ? DB::table('fn_cash_transfers')->where('tenant_id', $tenantId)->where('id', $transferId)->first()
            : null;

        if ($transfer === null) {
            throw FinanceException::make('form', 'Mutasi tidak ditemukan.');
        }

        return $transfer;
    }

    /**
     * Uraian baris kas: "Mutasi ke BSI Yayasan (Kas Yayasan) — Setoran SPP Oktober".
     */
    private function label(string $description, string $preposition, stdClass $account): string
    {
        $kas = $account->organization_id === null
            ? 'Kas Yayasan'
            : (string) DB::table('organizations')->where('id', $account->organization_id)->value('name');

        return mb_substr("Mutasi {$preposition} ".((string) $account->name)." ({$kas}) — {$description}", 0, ManageCashEntries::DESCRIPTION_MAX_LENGTH);
    }

    /**
     * @throws FinanceException
     */
    private function date(string $value): string
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? CarbonImmutable::parse($value, BusinessClock::timezone())
            : null;

        if ($date === null || $date > BusinessClock::today()) {
            throw FinanceException::make('entry_date', 'Isi tanggal yang benar dan tidak di masa depan.');
        }

        return $date->toDateString();
    }
}
