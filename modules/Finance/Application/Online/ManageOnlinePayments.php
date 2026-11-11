<?php

namespace Modules\Finance\Application\Online;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Ledger\LedgerDate;
use Modules\Finance\Application\Ledger\LedgerLock;
use Modules\Finance\Application\Payment\ManagePayments;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Invoice\InvoiceStatus;
use Modules\Finance\Domain\Online\OnlinePaymentMethod;
use Modules\Finance\Domain\Online\OnlinePaymentStatus;
use Modules\Finance\Domain\Payment\PaymentMethod;
use stdClass;

/**
 * Pesanan bayar online orang tua (PRD-006 §3–§5, MG-2). Hak akses (tautan orang tua/wali ke anak) dicek pemanggil;
 * notifikasi Midtrans masuk tanpa login, dipercaya hanya sesudah tanda tangannya cocok DAN status dicek ulang ke API.
 *
 * Aturan [PRD-006, disetujui owner 2026-11-03]:
 * - satu pesanan = tagihan terbuka yang dipilih, satu anak di satu lembaga, dibayar penuh (OD-MG-04); jumlah dihitung
 *   server dari database; biaya layanan per metode ditambahkan di atasnya (OD-MG-03);
 * - satu pesanan MENUNGGU per pendaftaran: membuat pesanan baru membatalkan yang lama (juga di Midtrans);
 * - lunas (settlement, atau capture + fraud accept) dengan jumlah sama persis → kuitansi ONLINE otomatis untuk tagihan
 *   yang masih terbuka, sisanya ditandai kelebihan bayar (OD-MG-07); aman diulang (kunci = order_id);
 * - expire → KEDALUWARSA, cancel → DIBATALKAN, deny/failure → GAGAL; tagihan tetap terbuka.
 *
 * @phpstan-type Quote array{invoices: list<array{id: string, description: string, due_date: string, outstanding: int}>, invoice_amount: int, fee_amount: int, gross_amount: int, method: OnlinePaymentMethod, expiry_minutes: int}
 */
final class ManageOnlinePayments
{
    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly OnlinePaymentSettings $settings,
        private readonly EnrolledStudents $students,
        private readonly ManagePayments $payments,
        private readonly AuditLogger $audit,
        private readonly LedgerLock $lock,
    ) {}

    /**
     * Hitung rincian bayar: tagihan terbuka yang dipilih (null = semua) + biaya layanan metode itu.
     *
     * @param  list<string>|null  $invoiceIds
     * @return Quote
     *
     * @throws FinanceException
     */
    public function quote(string $tenantId, string $organizationId, string $enrollmentId, ?array $invoiceIds, string $method): array
    {
        $methodCase = OnlinePaymentMethod::tryFrom($method);
        $settings = null;

        foreach ($this->settings->enabledMethods($tenantId) as $row) {
            if ($methodCase !== null && $row['method'] === $methodCase->value) {
                $settings = $row;
            }
        }

        if ($methodCase === null || $settings === null) {
            throw FinanceException::make('method', 'Pilih metode pembayaran yang tersedia.');
        }

        $ids = $invoiceIds === null ? null : array_values(array_unique(array_filter($invoiceIds, fn (string $id): bool => Str::isUuid($id))));

        $rows = DB::table('fn_invoices')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('enrollment_id', $enrollmentId)
            ->where('status', InvoiceStatus::Open->value)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids ?? []))
            ->orderBy('due_date')
            ->orderByRaw('period NULLS LAST')
            ->orderBy('id')
            ->get(['id', 'description', 'due_date', 'amount', 'paid_amount']);

        if ($ids !== null && count($rows) !== count($ids)) {
            throw FinanceException::make('invoice_ids', 'Sebagian tagihan yang dipilih sudah lunas atau tidak ditemukan. Muat ulang halaman.');
        }

        if ($rows->isEmpty()) {
            throw FinanceException::make('invoice_ids', 'Pilih minimal satu tagihan yang belum lunas.');
        }

        $invoices = [];
        $amount = 0;

        foreach ($rows as $row) {
            $outstanding = (int) $row->amount - (int) $row->paid_amount;
            $amount += $outstanding;
            $invoices[] = ['id' => (string) $row->id, 'description' => (string) $row->description, 'due_date' => substr((string) $row->due_date, 0, 10), 'outstanding' => $outstanding];
        }

        $fee = $methodCase->fee($amount, $settings['fee_value']);

        return [
            'invoices' => $invoices,
            'invoice_amount' => $amount,
            'fee_amount' => $fee,
            'gross_amount' => $amount + $fee,
            'method' => $methodCase,
            'expiry_minutes' => $methodCase->expiryMinutes(),
        ];
    }

    /**
     * Buat pesanan + transaksi Snap. Pesanan MENUNGGU yang lama untuk anak ini dibatalkan dulu.
     *
     * @param  list<string>|null  $invoiceIds
     * @return array{id: string, redirect_url: string}
     *
     * @throws FinanceException
     */
    public function create(string $tenantId, string $organizationId, string $enrollmentId, ?array $invoiceIds, string $method, string $finishUrl, ?string $membershipId): array
    {
        $student = $this->students->describe($tenantId, [$enrollmentId])[$enrollmentId] ?? null;

        if ($student === null || $student['organization_id'] !== $organizationId) {
            throw FinanceException::make('enrollment_id', 'Tagihan anak ini tidak ditemukan.');
        }

        $quote = $this->quote($tenantId, $organizationId, $enrollmentId, $invoiceIds, $method);

        $pending = DB::table('fn_online_payments')->where('enrollment_id', $enrollmentId)->where('status', OnlinePaymentStatus::Pending->value)->first();

        if ($pending !== null) {
            $this->cancelOrder($pending, $membershipId);
        }

        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) DB::table('organizations')->where('id', $organizationId)->value('code')));
        $orderId = mb_substr('EDU-'.$code.'-'.BusinessClock::today()->format('Ymd').'-'.strtoupper(Str::random(8)), 0, 50);
        $id = (string) Str::uuid7();
        $now = now();

        try {
            DB::table('fn_online_payments')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'enrollment_id' => $enrollmentId,
                'order_id' => $orderId,
                'invoices' => json_encode($quote['invoices'], JSON_THROW_ON_ERROR),
                'invoice_amount' => $quote['invoice_amount'],
                'fee_amount' => $quote['fee_amount'],
                'gross_amount' => $quote['gross_amount'],
                'method' => $quote['method']->value,
                'status' => OnlinePaymentStatus::Pending->value,
                'expires_at' => $now->copy()->addMinutes($quote['expiry_minutes']),
                'created_by_membership_id' => $membershipId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dua klik bersamaan: pesanan lain baru saja dibuat untuk anak ini.
            throw FinanceException::make('form', 'Ada pembayaran online lain yang baru dibuat untuk anak ini. Muat ulang halaman.');
        }

        $items = [];

        foreach ($quote['invoices'] as $invoice) {
            $items[] = ['id' => substr($invoice['id'], 0, 36), 'price' => $invoice['outstanding'], 'quantity' => 1, 'name' => mb_substr($invoice['description'], 0, 50)];
        }

        if ($quote['fee_amount'] > 0) {
            $items[] = ['id' => 'BIAYA-LAYANAN', 'price' => $quote['fee_amount'], 'quantity' => 1, 'name' => 'Biaya layanan '.$quote['method']->label()];
        }

        try {
            $snap = $this->gateway->createTransaction([
                'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $quote['gross_amount']],
                'item_details' => $items,
                // Data pribadi seminimal mungkin: hanya nama siswa (PRD-006 §12).
                'customer_details' => ['first_name' => mb_substr($student['name'], 0, 50)],
                'enabled_payments' => [$quote['method']->snapCode()],
                'expiry' => ['start_time' => $now->copy()->setTimezone(BusinessClock::timezone())->format('Y-m-d H:i:s O'), 'unit' => 'minutes', 'duration' => $quote['expiry_minutes']],
                'callbacks' => ['finish' => $finishUrl],
            ]);
        } catch (MidtransException $exception) {
            DB::table('fn_online_payments')->where('id', $id)->update(['status' => OnlinePaymentStatus::Failed->value, 'updated_at' => now()]);

            throw FinanceException::make('form', $exception->getMessage());
        }

        DB::table('fn_online_payments')->where('id', $id)->update(['snap_token' => $snap['token'], 'redirect_url' => $snap['redirect_url'], 'updated_at' => now()]);

        $this->audit->record(
            FinanceAuditAction::ONLINE_PAYMENT_CREATED,
            FinanceAuditAction::SUBJECT_ONLINE_PAYMENT,
            $id,
            ['order' => $orderId, 'tagihan' => $quote['invoice_amount'], 'biaya' => $quote['fee_amount'], 'total' => $quote['gross_amount'], 'metode' => $quote['method']->value],
            organizationId: $organizationId,
            tenantId: $tenantId,
        );

        return ['id' => $id, 'redirect_url' => $snap['redirect_url']];
    }

    /**
     * Orang tua membatalkan pesanan yang belum dibayar.
     *
     * @throws FinanceException
     */
    public function cancel(string $tenantId, string $id, ?string $membershipId): void
    {
        $order = DB::table('fn_online_payments')->where('tenant_id', $tenantId)->where('id', $id)->first();

        if ($order === null || (string) $order->status !== OnlinePaymentStatus::Pending->value) {
            throw FinanceException::make('form', 'Pembayaran ini sudah tidak menunggu.');
        }

        $this->cancelOrder($order, $membershipId);
    }

    /**
     * Bendahara menandai kelebihan bayar sudah dikembalikan ke orang tua (PRD-006 §8, OD-MG-07, MG-3). Pengembaliannya
     * sendiri di luar aplikasi (tunai / transfer / dashboard Midtrans).
     *
     * @throws FinanceException
     */
    public function settleOverpaid(string $tenantId, string $id, ?string $note): void
    {
        $note = $note === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $note));

        if (mb_strlen($note) > ManagePayments::NOTE_MAX_LENGTH) {
            throw FinanceException::make('note', 'Catatan maksimal '.ManagePayments::NOTE_MAX_LENGTH.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $id, $note): void {
            $order = DB::table('fn_online_payments')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();

            if ($order === null || (int) $order->overpaid_amount === 0 || $order->overpaid_settled_at !== null) {
                throw FinanceException::make('form', 'Tidak ada kelebihan bayar yang perlu ditandai untuk pembayaran ini.');
            }

            DB::table('fn_online_payments')->where('id', $id)->update(['overpaid_settled_at' => now(), 'updated_at' => now()]);

            $this->audit->record(
                FinanceAuditAction::ONLINE_OVERPAID_SETTLED,
                FinanceAuditAction::SUBJECT_ONLINE_PAYMENT,
                $id,
                array_filter(['order' => (string) $order->order_id, 'jumlah' => (int) $order->overpaid_amount, 'catatan' => $note === '' ? null : $note], fn (mixed $value): bool => $value !== null),
                organizationId: (string) $order->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Tanya status terkini ke Midtrans lalu terapkan (tombol "Cek status").
     *
     * @throws FinanceException
     */
    public function refresh(string $tenantId, string $id): void
    {
        $order = DB::table('fn_online_payments')->where('tenant_id', $tenantId)->where('id', $id)->first();

        if ($order === null) {
            throw FinanceException::make('form', 'Pembayaran tidak ditemukan.');
        }

        if ((string) $order->status === OnlinePaymentStatus::Paid->value) {
            return;
        }

        try {
            $status = $this->gateway->status((string) $order->order_id);
        } catch (MidtransException $exception) {
            throw FinanceException::make('form', $exception->getMessage());
        }

        $this->apply($order, $status);
    }

    /**
     * Notifikasi HTTP Midtrans. Mengembalikan false bila tanda tangannya tidak cocok (dijawab 403).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws MidtransException Midtrans tidak bisa dihubungi saat cek ulang (dijawab 503 agar Midtrans mengulang)
     */
    public function handleNotification(array $payload): bool
    {
        $orderId = is_string($payload['order_id'] ?? null) ? $payload['order_id'] : '';
        $valid = $this->gateway->verifySignature(
            $orderId,
            (string) ($payload['status_code'] ?? ''),
            (string) ($payload['gross_amount'] ?? ''),
            (string) ($payload['signature_key'] ?? ''),
        );
        $order = $orderId === '' ? null : DB::table('fn_online_payments')->where('order_id', $orderId)->first();

        DB::table('fn_online_payment_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $order?->tenant_id,
            'online_payment_id' => $order?->id,
            'order_id' => $orderId === '' ? null : mb_substr($orderId, 0, 50),
            'transaction_status' => is_string($payload['transaction_status'] ?? null) ? mb_substr($payload['transaction_status'], 0, 30) : null,
            'signature_valid' => $valid,
            // Tanpa data kartu (VA/QRIS tidak membawanya); tanda tangan tidak perlu disimpan.
            'payload' => json_encode(array_diff_key($payload, ['signature_key' => true]), JSON_PARTIAL_OUTPUT_ON_ERROR),
            'received_at' => now(),
        ]);

        if (! $valid) {
            Log::warning('Midtrans: notifikasi dengan tanda tangan tidak valid', ['order_id' => $orderId]);

            return false;
        }

        // Order asing (mis. milik aplikasi lain di akun yang sama): diterima tanpa tindakan.
        if ($order !== null) {
            $this->apply($order, $this->gateway->status($orderId));
        }

        return true;
    }

    /**
     * Terapkan status dari API Midtrans ke pesanan.
     *
     * @param  array<string, mixed>  $status
     */
    private function apply(stdClass $order, array $status): void
    {
        $transaction = (string) ($status['transaction_status'] ?? '');
        $fraud = (string) ($status['fraud_status'] ?? 'accept');

        if ($transaction === 'settlement' || ($transaction === 'capture' && $fraud === 'accept')) {
            $this->markPaid($order, $status);

            return;
        }

        $next = match ($transaction) {
            'expire' => OnlinePaymentStatus::Expired,
            'cancel' => OnlinePaymentStatus::Cancelled,
            'deny', 'failure' => OnlinePaymentStatus::Failed,
            default => null,
        };

        if ($next === null) {
            return;
        }

        $updated = DB::table('fn_online_payments')
            ->where('id', $order->id)
            ->where('status', OnlinePaymentStatus::Pending->value)
            ->update(['status' => $next->value, 'updated_at' => now()]);

        if ($updated > 0) {
            $this->audit->record(
                match ($next) {
                    OnlinePaymentStatus::Expired => FinanceAuditAction::ONLINE_PAYMENT_EXPIRED,
                    OnlinePaymentStatus::Cancelled => FinanceAuditAction::ONLINE_PAYMENT_CANCELLED,
                    default => FinanceAuditAction::ONLINE_PAYMENT_FAILED,
                },
                FinanceAuditAction::SUBJECT_ONLINE_PAYMENT,
                (string) $order->id,
                ['order' => (string) $order->order_id],
                organizationId: (string) $order->organization_id,
                tenantId: (string) $order->tenant_id,
            );
        }
    }

    /**
     * Lunas: kuitansi ONLINE untuk tagihan pesanan yang masih terbuka; sisanya kelebihan bayar (OD-MG-07).
     *
     * @param  array<string, mixed>  $status
     */
    private function markPaid(stdClass $order, array $status): void
    {
        $gross = (int) round((float) ($status['gross_amount'] ?? 0));

        if ($gross !== (int) $order->gross_amount) {
            Log::warning('Midtrans: jumlah lunas tidak sama dengan pesanan', ['order_id' => $order->order_id, 'midtrans' => $gross, 'pesanan' => (int) $order->gross_amount]);

            return;
        }

        DB::transaction(function () use ($order, $status): void {
            $locked = DB::table('fn_online_payments')->where('id', $order->id)->lockForUpdate()->first();

            if ($locked === null || (string) $locked->status === OnlinePaymentStatus::Paid->value) {
                return;
            }

            $tenantId = (string) $locked->tenant_id;
            $organizationId = (string) $locked->organization_id;
            /** @var list<array{id: string}> $invoices */
            $invoices = json_decode((string) $locked->invoices, true, flags: JSON_THROW_ON_ERROR);
            $open = DB::table('fn_invoices')
                ->whereIn('id', array_column($invoices, 'id'))
                ->where('enrollment_id', $locked->enrollment_id)
                ->where('status', InvoiceStatus::Open->value)
                ->get(['id', 'amount', 'paid_amount']);
            $outstanding = 0;

            foreach ($open as $invoice) {
                $outstanding += (int) $invoice->amount - (int) $invoice->paid_amount;
            }

            $paidOn = $this->paidOn($status);
            $note = 'Midtrans '.OnlinePaymentMethod::from((string) $locked->method)->label().' · '.((string) $locked->order_id);

            // Uang sudah masuk: lunas di periode yang sudah ditutup buku (PB-4) tetap dicatat, bertanggal hari ini.
            if ($this->lock->isLocked($tenantId, $paidOn)) {
                $note .= ' · lunas '.LedgerDate::long($paidOn).', dicatat hari ini karena periode itu sudah ditutup buku';
                $paidOn = BusinessClock::today()->toDateString();
            }

            $amount = min((int) $locked->invoice_amount, $outstanding);
            $paymentId = null;

            if ($amount > 0) {
                $paymentId = $this->payments->record(
                    $tenantId,
                    $organizationId,
                    (string) $locked->enrollment_id,
                    (string) $amount,
                    $paidOn,
                    PaymentMethod::Online->value,
                    mb_substr($note, 0, ManagePayments::NOTE_MAX_LENGTH),
                    array_values(array_map(fn (stdClass $invoice): string => (string) $invoice->id, $open->all())),
                    null,
                );
            }

            $overpaid = (int) $locked->invoice_amount - $amount;

            DB::table('fn_online_payments')->where('id', $locked->id)->update([
                'status' => OnlinePaymentStatus::Paid->value,
                'paid_at' => now(),
                'payment_id' => $paymentId,
                'overpaid_amount' => $overpaid,
                'midtrans_transaction_id' => is_string($status['transaction_id'] ?? null) ? mb_substr($status['transaction_id'], 0, 100) : null,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::ONLINE_PAYMENT_PAID,
                FinanceAuditAction::SUBJECT_ONLINE_PAYMENT,
                (string) $locked->id,
                array_filter([
                    'order' => (string) $locked->order_id,
                    'kuitansi' => $paymentId === null ? null : (string) DB::table('fn_payments')->where('id', $paymentId)->value('receipt_number'),
                    'jumlah' => $amount,
                    'kelebihan' => $overpaid > 0 ? $overpaid : null,
                ], fn (mixed $value): bool => $value !== null),
                organizationId: $organizationId,
                tenantId: $tenantId,
            );

            if ($overpaid > 0) {
                Log::notice('Pembayaran online: kelebihan bayar perlu dikembalikan', ['order_id' => $locked->order_id, 'kelebihan' => Rupiah::format($overpaid)]);
            }
        });
    }

    /**
     * Batalkan pesanan menunggu di Midtrans lalu di aplikasi. Bila Midtrans tidak bisa dihubungi, pesanan tetap
     * MENUNGGU (uangnya bisa saja sedang dibayar) dan pesan ramah ditampilkan.
     *
     * @throws FinanceException
     */
    private function cancelOrder(stdClass $order, ?string $membershipId): void
    {
        try {
            $this->gateway->cancel((string) $order->order_id);
        } catch (MidtransException $exception) {
            // Ditolak (mis. 404: orang tua belum pernah membuka halaman bayar, transaksi belum ada) → aman dibatalkan lokal.
            // Gangguan jaringan → jangan batalkan, uangnya bisa saja sedang dibayar.
            if (! $exception->isRejection()) {
                throw FinanceException::make('form', $exception->getMessage());
            }
        }

        $updated = DB::table('fn_online_payments')
            ->where('id', $order->id)
            ->where('status', OnlinePaymentStatus::Pending->value)
            ->update(['status' => OnlinePaymentStatus::Cancelled->value, 'updated_at' => now()]);

        if ($updated > 0) {
            $this->audit->record(
                FinanceAuditAction::ONLINE_PAYMENT_CANCELLED,
                FinanceAuditAction::SUBJECT_ONLINE_PAYMENT,
                (string) $order->id,
                ['order' => (string) $order->order_id, 'oleh' => $membershipId === null ? 'sistem' : 'orang tua/wali'],
                organizationId: (string) $order->organization_id,
                tenantId: (string) $order->tenant_id,
            );
        }
    }

    /**
     * Tanggal kuitansi = tanggal lunas di Midtrans (zona waktu sekolah), paling lambat hari ini.
     *
     * @param  array<string, mixed>  $status
     */
    private function paidOn(array $status): string
    {
        $today = BusinessClock::today();
        $raw = $status['settlement_time'] ?? $status['transaction_time'] ?? null;

        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
            return $today->toDateString();
        }

        // Waktu Midtrans dalam WIB (Asia/Jakarta).
        $date = CarbonImmutable::parse($raw, 'Asia/Jakarta')->setTimezone(BusinessClock::timezone())->startOfDay();

        return ($date > $today || $date < $today->subYear() ? $today : $date)->toDateString();
    }
}
