<?php

namespace Modules\Finance\Application\Payment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Domain\Payment\PaymentMethod;

/**
 * Data kuitansi satu pembayaran (PRD-003 §4, FN-3) untuk halaman cetak. Hak akses dicek pemanggil.
 *
 * @phpstan-type Receipt array{id: string, organization_id: string, enrollment_id: string, tenant: string, organization: string, receipt_number: string, paid_on: string, method: string, amount: int, amount_words: string, note: string|null, status: string, void_reason: string|null, recorded_by: string|null, student: array{name: string, class: string|null, student_number: string|null, nisn: string|null}, items: list<array{description: string, amount: int}>}
 */
final class Receipts
{
    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * @return Receipt|null
     */
    public function find(string $tenantId, string $paymentId): ?array
    {
        if (! Str::isUuid($paymentId)) {
            return null;
        }

        $payment = DB::table('fn_payments as p')
            ->join('organizations as o', 'o.id', '=', 'p.organization_id')
            ->join('tenants as t', 't.id', '=', 'p.tenant_id')
            ->leftJoin('memberships as m', 'm.id', '=', 'p.recorded_by_membership_id')
            ->leftJoin('persons as rp', 'rp.id', '=', 'm.person_id')
            ->where('p.tenant_id', $tenantId)
            ->where('p.id', $paymentId)
            ->first([
                'p.id', 'p.organization_id', 'p.enrollment_id', 'p.receipt_number', 'p.paid_on', 'p.method', 'p.amount', 'p.note', 'p.status', 'p.void_reason',
                'o.name as organization', 't.name as tenant', 'rp.name as recorded_by',
            ]);

        if ($payment === null) {
            return null;
        }

        $student = $this->students->describe($tenantId, [(string) $payment->enrollment_id])[(string) $payment->enrollment_id] ?? null;
        $items = [];

        foreach (DB::table('fn_payment_allocations as a')
            ->join('fn_invoices as i', 'i.id', '=', 'a.invoice_id')
            ->where('a.payment_id', $payment->id)
            ->orderBy('i.due_date')
            ->orderBy('i.id')
            ->get(['i.description', 'a.amount']) as $allocation) {
            $items[] = ['description' => (string) $allocation->description, 'amount' => (int) $allocation->amount];
        }

        return [
            'id' => (string) $payment->id,
            'organization_id' => (string) $payment->organization_id,
            'enrollment_id' => (string) $payment->enrollment_id,
            'tenant' => (string) $payment->tenant,
            'organization' => (string) $payment->organization,
            'receipt_number' => (string) $payment->receipt_number,
            'paid_on' => substr((string) $payment->paid_on, 0, 10),
            'method' => (PaymentMethod::tryFrom((string) $payment->method) ?? PaymentMethod::Cash)->label(),
            'amount' => (int) $payment->amount,
            'amount_words' => Terbilang::rupiah((int) $payment->amount),
            'note' => $payment->note === null ? null : (string) $payment->note,
            'status' => (string) $payment->status,
            'void_reason' => $payment->void_reason === null ? null : (string) $payment->void_reason,
            'recorded_by' => $payment->recorded_by === null ? null : (string) $payment->recorded_by,
            'student' => [
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => $student['class'] ?? null,
                'student_number' => $student['student_number'] ?? null,
                'nisn' => $student['nisn'] ?? null,
            ],
            'items' => $items,
        ];
    }
}
