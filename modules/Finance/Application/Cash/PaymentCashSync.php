<?php

namespace Modules\Finance\Application\Cash;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Domain\Cash\CashAccountKind;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Domain\Payment\PaymentStatus;
use stdClass;

/**
 * Pembayaran siswa (kuitansi kasir, PRD-003) → buku kas lembaga (PRD-004 §3, OD-KS-05, KS-2).
 *
 * Baris kas `source = PEMBAYARAN` adalah CERMINAN kuitansi; sumber aslinya tetap `fn_payments`. Aturan [ASUMSI KS-2]:
 * - satu kuitansi SAH = satu baris MASUK kategori sistem "Pembayaran siswa", tanggal = tanggal bayar;
 * - Tunai → Kas tunai lembaga; Transfer → rekening bank penerima transfer lembaga (bila belum ada: Kas tunai);
 * - kuitansi bertanggal SEBELUM tanggal saldo awal rekening tujuan tidak dicerminkan (sudah termasuk saldo awal);
 * - kuitansi dibatalkan → cerminannya BATAL (tetap tampil); ditolak bila saldo rekening menjadi minus (OD-KS-08);
 * - bila tanggal saldo awal atau rekening penerima transfer berubah, cerminan SAH yang tidak lagi cocok disusun ulang
 *   (dihapus & dibuat lagi di tempat yang benar) — boleh, karena cerminan bukan catatan uang aslinya.
 *
 * Aman diulang dan dijalankan bersamaan (kunci advisory per lembaga, indeks unik per kuitansi).
 */
final class PaymentCashSync
{
    public function __construct(
        private readonly ManageCashAccounts $accounts,
        private readonly ManageCashCategories $categories,
        private readonly CashBalances $balances,
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * Susun ulang cerminan seluruh kuitansi lembaga (dipanggil saat halaman Kas dibuka dan sesudah rekening diubah).
     */
    public function syncOrganization(string $tenantId, string $organizationId): void
    {
        $this->sync($tenantId, $organizationId, null, false);
    }

    /**
     * Cerminkan satu kuitansi sesudah dicatat atau dibatalkan. Dipanggil di dalam transaksi kasir, sehingga bila
     * ditolak (saldo minus) seluruh pembatalan ikut batal.
     *
     * @throws FinanceException
     */
    public function syncPayment(string $tenantId, string $organizationId, string $paymentId): void
    {
        $this->sync($tenantId, $organizationId, $paymentId, true);
    }

    /**
     * @throws FinanceException
     */
    private function sync(string $tenantId, string $organizationId, ?string $paymentId, bool $guardBalance): void
    {
        DB::transaction(function () use ($tenantId, $organizationId, $paymentId, $guardBalance): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance-cash-payments:{$organizationId}"]);

            $this->categories->ensureDefaults($tenantId);
            $this->accounts->ensureCashAccount($tenantId, $organizationId);

            $categoryId = (string) DB::table('fn_cash_categories')
                ->where('tenant_id', $tenantId)
                ->where('code', ManageCashCategories::SYSTEM_STUDENT_PAYMENTS)
                ->value('id');

            [$cashAccount, $transferAccount] = $this->targets($tenantId, $organizationId);

            $payments = DB::table('fn_payments')
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organizationId)
                ->when($paymentId !== null, fn ($query) => $query->where('id', $paymentId))
                ->get(['id', 'enrollment_id', 'receipt_number', 'paid_on', 'method', 'amount', 'status', 'void_reason', 'voided_at', 'voided_by_membership_id', 'recorded_by_membership_id']);

            $mirrors = [];

            foreach (DB::table('fn_cash_entries')
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organizationId)
                ->where('source', CashEntrySource::Payment->value)
                ->when($paymentId !== null, fn ($query) => $query->where('source_id', $paymentId))
                ->get(['id', 'source_id', 'account_id', 'entry_date', 'amount', 'status']) as $mirror) {
                $mirrors[(string) $mirror->source_id] = $mirror;
            }

            $missing = [];

            foreach ($payments as $payment) {
                $id = (string) $payment->id;
                $mirror = $mirrors[$id] ?? null;

                if ((string) $payment->status !== PaymentStatus::Valid->value) {
                    if ($mirror !== null && (string) $mirror->status === PaymentStatus::Valid->value) {
                        $this->voidMirror($mirror, $payment, $guardBalance);
                    }

                    continue;
                }

                $account = (string) $payment->method === PaymentMethod::Transfer->value ? $transferAccount : $cashAccount;
                $date = substr((string) $payment->paid_on, 0, 10);
                $wanted = $date >= $account['opening_date'];

                if ($mirror !== null) {
                    $fits = (string) $mirror->account_id === $account['id'] && substr((string) $mirror->entry_date, 0, 10) === $date;

                    if ($wanted && $fits) {
                        continue;
                    }

                    // Penataan ulang (saldo awal / rekening penerima berubah): cerminan lama dihapus.
                    DB::table('fn_cash_entries')->where('id', $mirror->id)->delete();
                }

                if ($wanted) {
                    $missing[] = [$payment, $account['id']];
                }
            }

            if ($missing === []) {
                return;
            }

            $enrollmentIds = [];

            foreach ($missing as [$payment]) {
                $enrollmentIds[(string) $payment->enrollment_id] = true;
            }

            $names = $this->students->describe($tenantId, array_keys($enrollmentIds));
            $now = now();
            $rows = [];

            foreach ($missing as [$payment, $accountId]) {
                $name = $names[(string) $payment->enrollment_id]['name'] ?? 'siswa';
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'organization_id' => $organizationId,
                    'account_id' => $accountId,
                    'category_id' => $categoryId,
                    'entry_date' => substr((string) $payment->paid_on, 0, 10),
                    'direction' => CashDirection::In->value,
                    'amount' => (int) $payment->amount,
                    'description' => mb_substr("Pembayaran siswa — {$name} ({$payment->receipt_number})", 0, ManageCashEntries::DESCRIPTION_MAX_LENGTH),
                    'source' => CashEntrySource::Payment->value,
                    'source_id' => (string) $payment->id,
                    'status' => PaymentStatus::Valid->value,
                    'recorded_by_membership_id' => $payment->recorded_by_membership_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('fn_cash_entries')->insert($chunk);
            }
        });
    }

    /**
     * Rekening tujuan: [Kas tunai, penerima transfer (atau Kas tunai)].
     *
     * @return array{0: array{id: string, opening_date: string}, 1: array{id: string, opening_date: string}}
     */
    private function targets(string $tenantId, string $organizationId): array
    {
        $cash = null;
        $transfer = null;

        foreach (DB::table('fn_cash_accounts')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query->where('kind', CashAccountKind::Cash->value)->orWhere('receives_transfers', true))
            ->get(['id', 'kind', 'opening_date', 'receives_transfers']) as $account) {
            $row = ['id' => (string) $account->id, 'opening_date' => substr((string) $account->opening_date, 0, 10)];

            if ((string) $account->kind === CashAccountKind::Cash->value) {
                $cash = $row;
            }

            if ((bool) $account->receives_transfers) {
                $transfer = $row;
            }
        }

        if ($cash === null) {
            throw FinanceException::make('form', 'Kas tunai lembaga tidak ditemukan.');
        }

        return [$cash, $transfer ?? $cash];
    }

    /**
     * @throws FinanceException saldo rekening akan minus (hanya bila $guardBalance)
     */
    private function voidMirror(stdClass $mirror, stdClass $payment, bool $guardBalance): void
    {
        $account = DB::table('fn_cash_accounts')->where('id', $mirror->account_id)->lockForUpdate()->first(['id', 'name']);

        if ($guardBalance && $account !== null && $this->balances->balance((string) $account->id) - (int) $mirror->amount < 0) {
            throw FinanceException::make('form', 'Pembatalan ditolak: saldo '.((string) $account->name).' di buku kas akan menjadi minus (uangnya sudah dipakai). Hubungi bendahara.');
        }

        DB::table('fn_cash_entries')->where('id', $mirror->id)->update([
            'status' => PaymentStatus::Void->value,
            'void_reason' => mb_substr('Pembayaran dibatalkan: '.((string) ($payment->void_reason ?? '-')), 0, ManageCashEntries::REASON_MAX_LENGTH),
            'voided_at' => $payment->voided_at ?? now(),
            'voided_by_membership_id' => $payment->voided_by_membership_id,
            'updated_at' => now(),
        ]);
    }
}
