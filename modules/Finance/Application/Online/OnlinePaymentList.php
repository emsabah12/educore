<?php

namespace Modules\Finance\Application\Online;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Domain\Online\OnlinePaymentMethod;
use Modules\Finance\Domain\Online\OnlinePaymentStatus;
use stdClass;

/**
 * Data pesanan bayar online untuk portal orang tua (PRD-006 §4, MG-2). Hak akses dicek pemanggil.
 *
 * @phpstan-type OrderRow array{id: string, enrollment_id: string, order_id: string, method: string, method_label: string, invoice_amount: int, fee_amount: int, gross_amount: int, status: string, status_label: string, expires_at: string, paid_at: string|null, payment_id: string|null, overpaid_amount: int, created_at: string, invoices: list<array{id: string, description: string, due_date: string, outstanding: int}>}
 */
final class OnlinePaymentList
{
    public const RECENT_LIMIT = 10;

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
            'created_at' => self::iso($row->created_at),
            'invoices' => $invoices,
        ];
    }
}
