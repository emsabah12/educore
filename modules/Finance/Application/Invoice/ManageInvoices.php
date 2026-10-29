<?php

namespace Modules\Finance\Application\Invoice;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Invoice\InvoiceStatus;

/**
 * Batalkan tagihan (PRD-003 §4, FN-2a). Hak akses (finance.invoices.manage di lembaga tagihan) dicek
 * pemanggil. Tagihan tidak pernah dihapus.
 *
 * Aturan [ASUMSI disetujui 2026-10-29]: hanya tagihan yang **belum ada pembayaran sah** (pembayaran dicatat
 * di FN-3; batalkan pembayarannya dulu); alasan wajib 1–200 karakter; tercatat di audit beserta alasannya.
 */
final class ManageInvoices
{
    public const REASON_MAX_LENGTH = 200;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Lembaga pemilik tagihan (untuk cek hak akses), atau null bila tidak ada di yayasan ini.
     */
    public function organizationOf(string $tenantId, string $invoiceId): ?string
    {
        if (! Str::isUuid($invoiceId)) {
            return null;
        }

        $organizationId = DB::table('fn_invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->value('organization_id');

        return $organizationId === null ? null : (string) $organizationId;
    }

    /**
     * @throws FinanceException
     */
    public function void(string $tenantId, string $invoiceId, string $reason, ?string $membershipId): void
    {
        $reason = (string) preg_replace('/\s+/u', ' ', trim($reason));

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', 'Alasan pembatalan wajib diisi, maksimal '.self::REASON_MAX_LENGTH.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $invoiceId, $reason, $membershipId): void {
            $invoice = Str::isUuid($invoiceId)
                ? DB::table('fn_invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->lockForUpdate()->first(['id', 'organization_id', 'enrollment_id', 'description', 'status', 'paid_amount', 'amount'])
                : null;

            if ($invoice === null) {
                throw FinanceException::make('form', 'Tagihan tidak ditemukan di yayasan ini.');
            }

            if ((string) $invoice->status === InvoiceStatus::Void->value) {
                throw FinanceException::make('form', 'Tagihan ini sudah dibatalkan.');
            }

            if ((int) $invoice->paid_amount > 0) {
                throw FinanceException::make('form', 'Tagihan ini sudah ada pembayarannya. Batalkan pembayarannya dulu.');
            }

            DB::table('fn_invoices')->where('id', $invoice->id)->update([
                'status' => InvoiceStatus::Void->value,
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by_membership_id' => $membershipId,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::INVOICE_VOIDED,
                FinanceAuditAction::SUBJECT_INVOICE,
                (string) $invoice->id,
                ['tagihan' => (string) $invoice->description, 'nominal' => (int) $invoice->amount, 'alasan' => $reason],
                organizationId: (string) $invoice->organization_id,
                tenantId: $tenantId,
            );
        });
    }
}
