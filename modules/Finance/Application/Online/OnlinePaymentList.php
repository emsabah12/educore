<?php

namespace Modules\Finance\Application\Online;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Domain\Online\OnlinePaymentMethod;
use Modules\Finance\Domain\Online\OnlinePaymentStatus;
use stdClass;

/**
 * Data pesanan bayar online untuk portal orang tua (PRD-006 §4, MG-2) dan halaman petugas (§9, MG-3). Hak akses dicek
 * pemanggil.
 *
 * @phpstan-type OrderRow array{id: string, enrollment_id: string, order_id: string, method: string, method_label: string, invoice_amount: int, fee_amount: int, gross_amount: int, status: string, status_label: string, expires_at: string, paid_at: string|null, payment_id: string|null, overpaid_amount: int, overpaid_settled_at: string|null, organization_id: string, created_at: string, invoices: list<array{id: string, description: string, due_date: string, outstanding: int}>}
 * @phpstan-type StaffRow array{id: string, organization_id: string, enrollment_id: string, order_id: string, student: string, class: string|null, method_label: string, gross_amount: int, invoice_amount: int, status: string, status_label: string, created_at: string, paid_at: string|null, overpaid_amount: int, overpaid_settled: bool}
 * @phpstan-type EventRow array{received_at: string, transaction_status: string|null, signature_valid: bool}
 */
final class OnlinePaymentList
{
    public const RECENT_LIMIT = 10;

    public const PER_PAGE = 50;

    /** Saringan khusus: lunas dengan kelebihan bayar yang belum dikembalikan (OD-MG-07). */
    public const FILTER_OVERPAID = 'KELEBIHAN';

    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * Daftar petugas (terbaru dulu), saring status (atau FILTER_OVERPAID) & bulan dibuat (Y-m), satu halaman.
     *
     * @param  list<string>  $organizationIds
     * @return array{rows: list<StaffRow>, total: int, page: int, last_page: int}
     */
    public function forStaff(string $tenantId, array $organizationIds, ?string $status, ?string $month, int $page): array
    {
        $query = DB::table('fn_online_payments')
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $organizationIds)
            ->when($status === self::FILTER_OVERPAID, fn ($q) => $q->where('overpaid_amount', '>', 0)->whereNull('overpaid_settled_at'))
            ->when($status !== null && $status !== self::FILTER_OVERPAID, fn ($q) => $q->where('status', $status))
            ->when($month !== null, function ($q) use ($month): void {
                $first = CarbonImmutable::parse($month.'-01');
                $q->where('created_at', '>=', $first->toDateTimeString())->where('created_at', '<', $first->addMonth()->toDateTimeString());
            });

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $enrollmentIds = [];

        foreach ($rows as $row) {
            $enrollmentIds[(string) $row->enrollment_id] = true;
        }

        $described = $this->students->describe($tenantId, array_keys($enrollmentIds));
        $result = [];

        foreach ($rows as $row) {
            $student = $described[(string) $row->enrollment_id] ?? null;
            $status = OnlinePaymentStatus::from((string) $row->status);
            $result[] = [
                'id' => (string) $row->id,
                'organization_id' => (string) $row->organization_id,
                'enrollment_id' => (string) $row->enrollment_id,
                'order_id' => (string) $row->order_id,
                'student' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => $student['class'] ?? null,
                'method_label' => OnlinePaymentMethod::from((string) $row->method)->label(),
                'gross_amount' => (int) $row->gross_amount,
                'invoice_amount' => (int) $row->invoice_amount,
                'status' => $status->value,
                'status_label' => $status->label(),
                'created_at' => self::iso($row->created_at),
                'paid_at' => $row->paid_at === null ? null : self::iso($row->paid_at),
                'overpaid_amount' => (int) $row->overpaid_amount,
                'overpaid_settled' => $row->overpaid_settled_at !== null,
            ];
        }

        return ['rows' => $result, 'total' => $total, 'page' => $page, 'last_page' => $lastPage];
    }

    /**
     * Jumlah pesanan lunas dengan kelebihan bayar yang belum dikembalikan, dan totalnya.
     *
     * @param  list<string>  $organizationIds
     * @return array{count: int, amount: int}
     */
    public function overpaid(string $tenantId, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return ['count' => 0, 'amount' => 0];
        }

        $row = DB::table('fn_online_payments')
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $organizationIds)
            ->where('overpaid_amount', '>', 0)
            ->whereNull('overpaid_settled_at')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(overpaid_amount), 0) AS amount')
            ->first();

        return ['count' => (int) ($row->count ?? 0), 'amount' => (int) ($row->amount ?? 0)];
    }

    /**
     * Pesanan MENUNGGU satu pendaftaran (untuk peringatan di Kasir), atau null.
     *
     * @return OrderRow|null
     */
    public function pendingFor(string $tenantId, string $enrollmentId): ?array
    {
        $row = DB::table('fn_online_payments')->where('tenant_id', $tenantId)->where('enrollment_id', $enrollmentId)->where('status', OnlinePaymentStatus::Pending->value)->first();

        return $row === null ? null : self::map($row);
    }

    /**
     * Jejak notifikasi Midtrans satu pesanan (terbaru dulu), tanpa isi lengkapnya.
     *
     * @return list<EventRow>
     */
    public function events(string $id): array
    {
        $events = [];

        foreach (DB::table('fn_online_payment_events')->where('online_payment_id', $id)->orderByDesc('received_at')->limit(50)->get(['received_at', 'transaction_status', 'signature_valid']) as $event) {
            $events[] = [
                'received_at' => self::iso($event->received_at),
                'transaction_status' => $event->transaction_status === null ? null : (string) $event->transaction_status,
                'signature_valid' => (bool) $event->signature_valid,
            ];
        }

        return $events;
    }

    /**
     * Pesanan terbaru (paling baru dulu) untuk pendaftaran-pendaftaran ini.
     *
     * @param  list<string>  $enrollmentIds
     * @return list<OrderRow>
     */
    public function recent(string $tenantId, array $enrollmentIds, int $limit = self::RECENT_LIMIT): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $rows = [];

        foreach (DB::table('fn_online_payments')
            ->where('tenant_id', $tenantId)
            ->whereIn('enrollment_id', $enrollmentIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get() as $row) {
            $rows[] = self::map($row);
        }

        return $rows;
    }

    /**
     * @return OrderRow|null
     */
    public function find(string $tenantId, string $id): ?array
    {
        $row = Str::isUuid($id) ? DB::table('fn_online_payments')->where('tenant_id', $tenantId)->where('id', $id)->first() : null;

        return $row === null ? null : self::map($row);
    }

    /**
     * Waktu database → ISO 8601 dengan offset (mis. "2026-11-04T02:00:00+00:00"), aman dibaca `new Date()` di browser.
     */
    private static function iso(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->toIso8601String();
    }

    /**
     * @return OrderRow
     */
    private static function map(stdClass $row): array
    {
        $method = OnlinePaymentMethod::from((string) $row->method);
        $status = OnlinePaymentStatus::from((string) $row->status);
        /** @var list<array{id: string, description: string, due_date: string, outstanding: int}> $invoices */
        $invoices = json_decode((string) $row->invoices, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $row->id,
            'enrollment_id' => (string) $row->enrollment_id,
            'order_id' => (string) $row->order_id,
            'method' => $method->value,
            'method_label' => $method->label(),
            'invoice_amount' => (int) $row->invoice_amount,
            'fee_amount' => (int) $row->fee_amount,
            'gross_amount' => (int) $row->gross_amount,
            'status' => $status->value,
            'status_label' => $status->label(),
            'expires_at' => self::iso($row->expires_at),
            'paid_at' => $row->paid_at === null ? null : self::iso($row->paid_at),
            'payment_id' => $row->payment_id === null ? null : (string) $row->payment_id,
            'overpaid_amount' => (int) $row->overpaid_amount,
            'overpaid_settled_at' => $row->overpaid_settled_at === null ? null : self::iso($row->overpaid_settled_at),
            'organization_id' => (string) $row->organization_id,
            'created_at' => self::iso($row->created_at),
            'invoices' => $invoices,
        ];
    }
}
