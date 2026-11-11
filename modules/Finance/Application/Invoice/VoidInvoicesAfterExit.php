<?php

namespace Modules\Finance\Application\Invoice;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\Events\EnrollmentClosed;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Ledger\LedgerLock;
use Modules\Finance\Domain\Invoice\InvoiceStatus;

/**
 * Siswa keluar → tagihan bulanan untuk bulan SESUDAH bulan keluarnya yang belum dibayar dibatalkan
 * otomatis (PRD-003 OD-FN-07, FN-2b). Tagihan bulan keluarnya tetap (siswa masih aktif sebagian bulan);
 * tagihan sekali bayar tidak disentuh. Berjalan sinkron di dalam transaksi penutupan pendaftaran.
 */
final class VoidInvoicesAfterExit
{
    public const REASON = 'Siswa keluar (dibatalkan otomatis)';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LedgerLock $lock,
    ) {}

    public function handle(EnrollmentClosed $event): void
    {
        $nextMonth = CarbonImmutable::parse($event->endDate)->startOfMonth()->addMonth()->toDateString();
        // Bulan yang sudah ditutup buku (PB-4) tidak disentuh; tagihannya tetap, petugas membereskannya sesudah buka kunci.
        $lockedThrough = $this->lock->lockedThrough($event->tenantId);

        $voided = DB::table('fn_invoices')
            ->where('tenant_id', $event->tenantId)
            ->where('enrollment_id', $event->enrollmentId)
            ->whereNotNull('period')
            ->where('period', '>=', $nextMonth)
            ->when($lockedThrough !== null, fn ($query) => $query->where('period', '>', $lockedThrough))
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->where('paid_amount', 0)
            ->update([
                'status' => InvoiceStatus::Void->value,
                'void_reason' => self::REASON,
                'voided_at' => now(),
                'updated_at' => now(),
            ]);

        if ($voided > 0) {
            $this->audit->record(
                FinanceAuditAction::INVOICES_VOIDED_ON_EXIT,
                FinanceAuditAction::SUBJECT_ENROLLMENT,
                $event->enrollmentId,
                ['tanggal_keluar' => $event->endDate, 'jumlah' => $voided],
                organizationId: $event->organizationId,
                tenantId: $event->tenantId,
            );
        }
    }
}
