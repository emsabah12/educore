<?php

namespace Modules\Finance\Application\Report;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Rekap penerimaan (PRD-003 §3 FN-4, §6; rincian FN-4b §18). Hak akses (lembaga dalam cakupan) dicek pemanggil.
 *
 * - Periode = satu bulan menurut TANGGAL BAYAR (bukan tanggal input); boleh dipersempit ke satu tanggal untuk
 *   daftar transaksi.
 * - Hanya pembayaran SAH yang dihitung; pembayaran batal tetap muncul di daftar transaksi (bertanda) dan
 *   dihitung terpisah.
 *
 * @phpstan-type Totals array{count: int, amount: int, cash: int, transfer: int, voided_count: int, voided_amount: int}
 * @phpstan-type DayRow array{date: string, count: int, cash: int, transfer: int, total: int}
 * @phpstan-type FeeTypeRow array{name: string, amount: int}
 * @phpstan-type OrganizationRow array{organization_id: string, count: int, total: int}
 * @phpstan-type Transaction array{id: string, organization_id: string, enrollment_id: string, receipt_number: string, paid_on: string, method: string, amount: int, status: string, void_reason: string|null, name: string, class: string|null, items: list<string>, recorded_by: string|null}
 */
final class ReceiptRecap
{
    public const PER_PAGE = 50;

    /** Pilihan bulan: bulan ini dan sekian bulan ke belakang. */
    public const MONTHS_BACK = 24;

    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * @return list<array{value: string, label: string}> terbaru dulu
     */
    public static function monthOptions(CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();
        $options = [];

        for ($i = 0; $i <= self::MONTHS_BACK; $i++) {
            $options[] = ['value' => $month->format('Y-m'), 'label' => self::monthLabel($month)];
            $month = $month->subMonth();
        }

        return $options;
    }

    public static function monthLabel(CarbonImmutable $month): string
    {
        return self::MONTHS[$month->month].' '.$month->year;
    }

    /**
     * Ringkasan bulan: total, per hari, per jenis biaya, per lembaga.
     *
     * @param  list<string>  $organizationIds
     * @return array{totals: Totals, days: list<DayRow>, fee_types: list<FeeTypeRow>, organizations: list<OrganizationRow>}
     */
    public function summary(string $tenantId, array $organizationIds, string $month): array
    {
        [$from, $to] = self::range($month);

        $totals = ['count' => 0, 'amount' => 0, 'cash' => 0, 'transfer' => 0, 'voided_count' => 0, 'voided_amount' => 0];
        $days = [];
        $organizations = [];

        foreach ($this->payments($tenantId, $organizationIds, $from, $to)
            ->groupBy('paid_on', 'method', 'status', 'organization_id')
            ->get(['paid_on', 'method', 'status', 'organization_id', DB::raw('COUNT(*) AS count'), DB::raw('SUM(amount) AS amount')]) as $row) {
            $count = (int) $row->count;
            $amount = (int) $row->amount;

            if ((string) $row->status !== PaymentStatus::Valid->value) {
                $totals['voided_count'] += $count;
                $totals['voided_amount'] += $amount;

                continue;
            }

            $date = substr((string) $row->paid_on, 0, 10);
            $cash = (string) $row->method === PaymentMethod::Cash->value;
            $organizationId = (string) $row->organization_id;

            $totals['count'] += $count;
            $totals['amount'] += $amount;
            $totals[$cash ? 'cash' : 'transfer'] += $amount;

            $days[$date] ??= ['date' => $date, 'count' => 0, 'cash' => 0, 'transfer' => 0, 'total' => 0];
            $days[$date]['count'] += $count;
            $days[$date][$cash ? 'cash' : 'transfer'] += $amount;
            $days[$date]['total'] += $amount;

            $organizations[$organizationId] ??= ['organization_id' => $organizationId, 'count' => 0, 'total' => 0];
            $organizations[$organizationId]['count'] += $count;
            $organizations[$organizationId]['total'] += $amount;
        }

        ksort($days);
        $organizationRows = array_values($organizations);
        usort($organizationRows, fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['organization_id'], $b['organization_id']));

        $feeTypes = [];

        foreach (DB::table('fn_payment_allocations as a')
            ->join('fn_payments as p', 'p.id', '=', 'a.payment_id')
            ->join('fn_invoices as i', 'i.id', '=', 'a.invoice_id')
            ->join('fn_fee_types as t', 't.id', '=', 'i.fee_type_id')
            ->where('p.tenant_id', $tenantId)
            ->whereIn('p.organization_id', $organizationIds)
            ->whereBetween('p.paid_on', [$from, $to])
            ->where('p.status', PaymentStatus::Valid->value)
            ->groupBy('t.id', 't.name')
            ->orderByRaw('SUM(a.amount) DESC')
            ->orderBy('t.name')
            ->get(['t.name', DB::raw('SUM(a.amount) AS amount')]) as $row) {
            $feeTypes[] = ['name' => (string) $row->name, 'amount' => (int) $row->amount];
        }

        return [
            'totals' => $totals,
            'days' => array_values($days),
            'fee_types' => $feeTypes,
            'organizations' => $organizationRows,
        ];
    }

    /**
     * Daftar transaksi bulan itu (atau satu tanggal), termasuk yang batal; urut tanggal bayar lalu nomor kuitansi.
     *
     * @param  list<string>  $organizationIds
     * @return list<Transaction>
     */
    public function transactions(string $tenantId, array $organizationIds, string $month, ?string $day = null): array
    {
        [$from, $to] = $day === null ? self::range($month) : [$day, $day];

        $payments = $this->payments($tenantId, $organizationIds, $from, $to, 'p')
            ->leftJoin('memberships as m', 'm.id', '=', 'p.recorded_by_membership_id')
            ->leftJoin('persons as rp', 'rp.id', '=', 'm.person_id')
            ->orderBy('p.paid_on')
            ->orderBy('p.organization_id')
            ->orderBy('p.receipt_year')
            ->orderBy('p.receipt_sequence')
            ->get(['p.id', 'p.organization_id', 'p.enrollment_id', 'p.receipt_number', 'p.paid_on', 'p.method', 'p.amount', 'p.status', 'p.void_reason', 'rp.name as recorded_by']);

        $paymentIds = [];
        $enrollmentIds = [];

        foreach ($payments as $payment) {
            $paymentIds[] = (string) $payment->id;
            $enrollmentIds[(string) $payment->enrollment_id] = true;
        }

        $items = [];

        foreach (array_chunk($paymentIds, 1000) as $chunk) {
            foreach (DB::table('fn_payment_allocations as a')
                ->join('fn_invoices as i', 'i.id', '=', 'a.invoice_id')
                ->whereIn('a.payment_id', $chunk)
                ->orderBy('i.due_date')
                ->orderBy('i.id')
                ->get(['a.payment_id', 'i.description']) as $allocation) {
                $items[(string) $allocation->payment_id][] = (string) $allocation->description;
            }
        }

        $students = $this->students->describe($tenantId, array_keys($enrollmentIds));
        $rows = [];

        foreach ($payments as $payment) {
            $student = $students[(string) $payment->enrollment_id] ?? null;

            $rows[] = [
                'id' => (string) $payment->id,
                'organization_id' => (string) $payment->organization_id,
                'enrollment_id' => (string) $payment->enrollment_id,
                'receipt_number' => (string) $payment->receipt_number,
                'paid_on' => substr((string) $payment->paid_on, 0, 10),
                'method' => (PaymentMethod::tryFrom((string) $payment->method) ?? PaymentMethod::Cash)->label(),
                'amount' => (int) $payment->amount,
                'status' => (string) $payment->status,
                'void_reason' => $payment->void_reason === null ? null : (string) $payment->void_reason,
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => ($student['active'] ?? false) ? $student['class'] ?? null : null,
                'items' => $items[(string) $payment->id] ?? [],
                'recorded_by' => $payment->recorded_by === null ? null : (string) $payment->recorded_by,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string} tanggal pertama & terakhir bulan (Y-m-d)
     */
    public static function range(string $month): array
    {
        $first = CarbonImmutable::parse($month.'-01');

        return [$first->toDateString(), $first->endOfMonth()->toDateString()];
    }

    /**
     * @param  list<string>  $organizationIds
     */
    private function payments(string $tenantId, array $organizationIds, string $from, string $to, ?string $alias = null): Builder
    {
        $prefix = $alias === null ? '' : $alias.'.';

        return DB::table('fn_payments'.($alias === null ? '' : " as {$alias}"))
            ->where($prefix.'tenant_id', $tenantId)
            ->whereIn($prefix.'organization_id', $organizationIds)
            ->whereBetween($prefix.'paid_on', [$from, $to]);
    }
}
