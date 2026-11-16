<?php

namespace Modules\Finance\Application\Invoice;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Dormitory\Contracts\DormitoryResidents;
use Modules\Dormitory\Contracts\Events\ResidencyEnded;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Ledger\LedgerLock;
use Modules\Finance\Domain\Invoice\InvoiceStatus;

/**
 * Santri keluar asrama → biaya asrama (jenis biaya "Hanya penghuni asrama", FN-6, PRD-003 §20) untuk bulan SESUDAH
 * bulan keluarnya yang belum dibayar dibatalkan otomatis [OWNER 2026-11-17], dengan pola yang sama seperti siswa
 * keluar (FN-2b, VoidInvoicesAfterExit):
 *
 * - hanya tagihan bulanan; tagihan sekali bayar tidak disentuh;
 * - tagihan yang sudah ada pembayarannya tetap (diselesaikan bendahara);
 * - bulan yang sudah ditutup buku (PB-4) tidak disentuh;
 * - tagihan bulan yang masih ditinggali santri itu (mis. mutasi ke kamar/asrama lain milik lembaga yang sama, atau
 *   sudah masuk lagi) TIDAK dibatalkan: dicek per bulan lewat kontrak DormitoryResidents.
 *
 * Berjalan sinkron di dalam transaksi Dormitory yang mengakhiri penempatan.
 */
final class VoidDormitoryInvoicesAfterLeaving
{
    public const REASON = 'Keluar asrama (dibatalkan otomatis)';

    public function __construct(
        private readonly EnrolledStudents $students,
        private readonly DormitoryResidents $residents,
        private readonly AuditLogger $audit,
        private readonly LedgerLock $lock,
    ) {}

    public function handle(ResidencyEnded $event): void
    {
        $enrollmentIds = $this->students->enrollmentIdsOfMember($event->tenantId, $event->membershipId);

        if ($enrollmentIds === []) {
            return;
        }

        $nextMonth = CarbonImmutable::parse($event->endDate)->startOfMonth()->addMonth()->toDateString();
        $lockedThrough = $this->lock->lockedThrough($event->tenantId);

        $candidates = DB::table('fn_invoices as i')
            ->join('fn_fee_types as t', 't.id', '=', 'i.fee_type_id')
            ->where('i.tenant_id', $event->tenantId)
            ->whereIn('i.enrollment_id', $enrollmentIds)
            ->where('t.dormitory_only', true)
            ->whereNotNull('i.period')
            ->where('i.period', '>=', $nextMonth)
            ->when($lockedThrough !== null, fn ($query) => $query->where('i.period', '>', $lockedThrough))
            ->where('i.status', '!=', InvoiceStatus::Void->value)
            ->where('i.paid_amount', 0)
            ->lockForUpdate()
            ->get(['i.id', 'i.organization_id', 'i.enrollment_id', 'i.period']);

        $void = [];
        $organizations = [];

        foreach ($candidates as $invoice) {
            $month = CarbonImmutable::parse((string) $invoice->period)->startOfMonth();
            $stillResident = $this->residents->residentsDuring(
                $event->tenantId,
                (string) $invoice->organization_id,
                [$event->membershipId],
                $month->toDateString(),
                $month->endOfMonth()->toDateString(),
            ) !== [];

            if (! $stillResident) {
                $void[(string) $invoice->enrollment_id][] = (string) $invoice->id;
                $organizations[(string) $invoice->enrollment_id] = (string) $invoice->organization_id;
            }
        }

        foreach ($void as $enrollmentId => $invoiceIds) {
            DB::table('fn_invoices')->whereIn('id', $invoiceIds)->update([
                'status' => InvoiceStatus::Void->value,
                'void_reason' => self::REASON,
                'voided_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::INVOICES_VOIDED_ON_DORMITORY_EXIT,
                FinanceAuditAction::SUBJECT_ENROLLMENT,
                $enrollmentId,
                ['tanggal_keluar_asrama' => $event->endDate, 'jumlah' => count($invoiceIds)],
                organizationId: $organizations[$enrollmentId] ?? null,
                tenantId: $event->tenantId,
            );
        }
    }
}
