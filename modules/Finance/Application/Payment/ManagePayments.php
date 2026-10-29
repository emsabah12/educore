<?php

namespace Modules\Finance\Application\Payment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Invoice\InvoiceStatus;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Catat & batalkan pembayaran siswa (PRD-003 §4, FN-3). Hak akses (finance.payments.manage di lembaga
 * pendaftaran) dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui 2026-10-30, rincian FN-3]:
 * - jumlah Rp 1 s.d. Rp 1.000.000.000; tanggal bayar tidak di masa depan dan paling lama 1 tahun lalu;
 * - dialokasikan ke tagihan terbuka yang **dipilih** (atau semua bila tidak memilih), urut jatuh tempo
 *   terlama; tagihan terakhir boleh terbayar sebagian (cicil); **kelebihan bayar ditolak** (OD-FN-06);
 * - nomor kuitansi `KODE-LEMBAGA/TAHUN/000001`, urut per lembaga per tahun tanggal bayar, tidak lompat/dobel
 *   walau dua petugas menyimpan bersamaan (kunci per lembaga × tahun);
 * - batal: alasan wajib; alokasinya dikembalikan sehingga tagihan terbuka lagi; kuitansi tetap ada (BATAL).
 */
final class ManagePayments
{
    public const MAX_AMOUNT = 1_000_000_000;

    public const NOTE_MAX_LENGTH = 200;

    public const REASON_MAX_LENGTH = 200;

    public function __construct(
        private readonly EnrolledStudents $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>|null  $invoiceIds  tagihan yang dipilih; null = semua tagihan terbuka
     * @return string id pembayaran
     *
     * @throws FinanceException
     */
    public function record(string $tenantId, string $organizationId, string $enrollmentId, mixed $amount, string $paidOn, string $method, ?string $note, ?array $invoiceIds, ?string $membershipId): string
    {
        $value = Rupiah::parse($amount, 'amount', 'jumlah bayar', 1, self::MAX_AMOUNT);
        $methodCase = PaymentMethod::tryFrom($method) ?? throw FinanceException::make('method', 'Pilih cara bayar: tunai atau transfer.');
        $date = $this->paidOn($paidOn);
        $note = $note === null ? null : trim((string) preg_replace('/\s+/u', ' ', $note));
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw FinanceException::make('note', 'Catatan maksimal '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        $student = $this->students->describe($tenantId, [$enrollmentId])[$enrollmentId] ?? null;

        if ($student === null || $student['organization_id'] !== $organizationId) {
            throw FinanceException::make('enrollment_id', 'Pilih siswa lembaga ini.');
        }

        return DB::transaction(function () use ($tenantId, $organizationId, $enrollmentId, $value, $methodCase, $date, $note, $invoiceIds, $membershipId): string {
            $invoices = DB::table('fn_invoices')
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organizationId)
                ->where('enrollment_id', $enrollmentId)
                ->where('status', InvoiceStatus::Open->value)
                ->when($invoiceIds !== null, fn ($query) => $query->whereIn('id', array_values(array_filter($invoiceIds ?? [], fn (string $id): bool => Str::isUuid($id)))))
                ->orderBy('due_date')
                ->orderByRaw('period NULLS LAST')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'description', 'amount', 'paid_amount']);

            if ($invoiceIds !== null && count($invoices) !== count(array_unique($invoiceIds))) {
                throw FinanceException::make('invoice_ids', 'Sebagian tagihan yang dipilih sudah lunas, batal, atau bukan milik siswa ini. Muat ulang halaman.');
            }

            if ($invoices->isEmpty()) {
                throw FinanceException::make('invoice_ids', 'Tidak ada tagihan yang belum lunas untuk dibayar.');
            }

            $outstanding = 0;

            foreach ($invoices as $invoice) {
                $outstanding += (int) $invoice->amount - (int) $invoice->paid_amount;
            }

            if ($value > $outstanding) {
                throw FinanceException::make('amount', 'Jumlah bayar melebihi sisa tagihan yang dipilih ('.Rupiah::format($outstanding).'). Kelebihan bayar belum bisa diterima.');
            }

            [$year, $sequence, $number] = $this->nextReceipt($organizationId, $date);
            $paymentId = (string) Str::uuid7();
            $now = now();

            DB::table('fn_payments')->insert([
                'id' => $paymentId,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'enrollment_id' => $enrollmentId,
                'receipt_number' => $number,
                'receipt_year' => $year,
                'receipt_sequence' => $sequence,
                'paid_on' => $date->toDateString(),
                'method' => $methodCase->value,
                'amount' => $value,
                'note' => $note,
                'status' => PaymentStatus::Valid->value,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $left = $value;

            foreach ($invoices as $invoice) {
                if ($left === 0) {
                    break;
                }

                $due = (int) $invoice->amount - (int) $invoice->paid_amount;
                $portion = min($due, $left);
                $left -= $portion;
                $paid = (int) $invoice->paid_amount + $portion;

                DB::table('fn_payment_allocations')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoice->id,
                    'amount' => $portion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('fn_invoices')->where('id', $invoice->id)->update([
                    'paid_amount' => $paid,
                    'status' => $paid === (int) $invoice->amount ? InvoiceStatus::Paid->value : InvoiceStatus::Open->value,
                    'updated_at' => $now,
                ]);
            }

            $this->audit->record(
                FinanceAuditAction::PAYMENT_RECORDED,
                FinanceAuditAction::SUBJECT_PAYMENT,
                $paymentId,
                ['kuitansi' => $number, 'jumlah' => $value, 'cara_bayar' => $methodCase->value, 'tanggal' => $date->toDateString()],
                organizationId: $organizationId,
                tenantId: $tenantId,
            );

            return $paymentId;
        });
    }

    /**
     * Lembaga pemilik pembayaran (untuk cek hak akses), atau null bila tidak ada di yayasan ini.
     */
    public function organizationOf(string $tenantId, string $paymentId): ?string
    {
        if (! Str::isUuid($paymentId)) {
            return null;
        }

        $organizationId = DB::table('fn_payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->value('organization_id');

        return $organizationId === null ? null : (string) $organizationId;
    }

    /**
     * @throws FinanceException
     */
    public function void(string $tenantId, string $paymentId, string $reason, ?string $membershipId): void
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', 'Alasan pembatalan wajib diisi, maksimal '.self::REASON_MAX_LENGTH.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $paymentId, $reason, $membershipId): void {
            $payment = Str::isUuid($paymentId)
                ? DB::table('fn_payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->lockForUpdate()->first(['id', 'organization_id', 'receipt_number', 'amount', 'status'])
                : null;

            if ($payment === null) {
                throw FinanceException::make('form', 'Pembayaran tidak ditemukan di yayasan ini.');
            }

            if ((string) $payment->status === PaymentStatus::Void->value) {
                throw FinanceException::make('form', 'Pembayaran ini sudah dibatalkan.');
            }

            $now = now();

            foreach (DB::table('fn_payment_allocations')->where('payment_id', $payment->id)->get(['invoice_id', 'amount']) as $allocation) {
                $invoice = DB::table('fn_invoices')->where('id', $allocation->invoice_id)->lockForUpdate()->first(['id', 'amount', 'paid_amount']);

                if ($invoice === null) {
                    continue;
                }

                $paid = max(0, (int) $invoice->paid_amount - (int) $allocation->amount);

                DB::table('fn_invoices')->where('id', $invoice->id)->update([
                    'paid_amount' => $paid,
                    'status' => $paid === (int) $invoice->amount ? InvoiceStatus::Paid->value : InvoiceStatus::Open->value,
                    'updated_at' => $now,
                ]);
            }

            DB::table('fn_payments')->where('id', $payment->id)->update([
                'status' => PaymentStatus::Void->value,
                'void_reason' => $reason,
                'voided_at' => $now,
                'voided_by_membership_id' => $membershipId,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                FinanceAuditAction::PAYMENT_VOIDED,
                FinanceAuditAction::SUBJECT_PAYMENT,
                (string) $payment->id,
                ['kuitansi' => (string) $payment->receipt_number, 'jumlah' => (int) $payment->amount, 'alasan' => $reason],
                organizationId: (string) $payment->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    private function paidOn(string $value): CarbonImmutable
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? CarbonImmutable::parse($value, BusinessClock::timezone())
            : null;
        $today = BusinessClock::today();

        if ($date === null || $date > $today || $date < $today->subYear()) {
            throw FinanceException::make('paid_on', 'Tanggal bayar tidak boleh di masa depan atau lebih dari 1 tahun lalu.');
        }

        return $date;
    }

    /**
     * Nomor kuitansi berikutnya untuk lembaga × tahun tanggal bayar. Dipanggil di dalam transaksi.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function nextReceipt(string $organizationId, CarbonImmutable $date): array
    {
        $year = $date->year;
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance-receipt:{$organizationId}:{$year}"]);

        $sequence = (int) DB::table('fn_payments')->where('organization_id', $organizationId)->where('receipt_year', $year)->max('receipt_sequence') + 1;
        $code = (string) DB::table('organizations')->where('id', $organizationId)->value('code');

        return [$year, $sequence, sprintf('%s/%d/%06d', $code, $year, $sequence)];
    }
}
