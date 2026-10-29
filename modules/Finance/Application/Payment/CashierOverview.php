<?php

namespace Modules\Finance\Application\Payment;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Domain\Invoice\InvoiceStatus;
use Modules\Finance\Domain\Payment\PaymentMethod;

/**
 * Data layar kasir untuk satu siswa (pendaftaran) di lembaga (PRD-003 §6, FN-3): tagihan yang belum
 * lunas (semua tahun ajaran) dan riwayat pembayaran. Hak akses dicek pemanggil.
 *
 * @phpstan-type OpenInvoice array{id: string, description: string, due_date: string, amount: int, paid_amount: int, outstanding: int, overdue: bool}
 * @phpstan-type PaymentRow array{id: string, receipt_number: string, paid_on: string, method: string, amount: int, status: string, void_reason: string|null, items: list<string>}
 */
final class CashierOverview
{
    /** Riwayat pembayaran yang ditampilkan di layar kasir. */
    public const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * @return array{student: array{enrollment_id: string, name: string, class: string|null, student_number: string|null, nisn: string|null, active: bool}, invoices: list<OpenInvoice>, outstanding: int, payments: list<PaymentRow>}|null
     */
    public function forStudent(string $tenantId, string $organizationId, string $enrollmentId, string $today): ?array
    {
        $student = $this->students->describe($tenantId, [$enrollmentId])[$enrollmentId] ?? null;

        if ($student === null || $student['organization_id'] !== $organizationId) {
            return null;
        }

        $invoices = [];
        $outstanding = 0;

        foreach (DB::table('fn_invoices')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('enrollment_id', $enrollmentId)
            ->where('status', InvoiceStatus::Open->value)
            ->orderBy('due_date')
            ->orderByRaw('period NULLS LAST')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'description', 'due_date', 'amount', 'paid_amount']) as $invoice) {
            $due = (int) $invoice->amount - (int) $invoice->paid_amount;
            $dueDate = substr((string) $invoice->due_date, 0, 10);
            $outstanding += $due;

            $invoices[] = [
                'id' => (string) $invoice->id,
                'description' => (string) $invoice->description,
                'due_date' => $dueDate,
                'amount' => (int) $invoice->amount,
                'paid_amount' => (int) $invoice->paid_amount,
                'outstanding' => $due,
                'overdue' => $dueDate < $today,
            ];
        }

        $payments = DB::table('fn_payments')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('enrollment_id', $enrollmentId)
            ->orderByDesc('paid_on')
            ->orderByDesc('receipt_year')
            ->orderByDesc('receipt_sequence')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'receipt_number', 'paid_on', 'method', 'amount', 'status', 'void_reason']);

        $items = [];

        foreach (DB::table('fn_payment_allocations as a')
            ->join('fn_invoices as i', 'i.id', '=', 'a.invoice_id')
            ->whereIn('a.payment_id', $payments->pluck('id')->all())
            ->orderBy('i.due_date')
            ->get(['a.payment_id', 'i.description']) as $allocation) {
            $items[(string) $allocation->payment_id][] = (string) $allocation->description;
        }

        $history = [];

        foreach ($payments as $payment) {
            $history[] = [
                'id' => (string) $payment->id,
                'receipt_number' => (string) $payment->receipt_number,
                'paid_on' => substr((string) $payment->paid_on, 0, 10),
                'method' => (PaymentMethod::tryFrom((string) $payment->method) ?? PaymentMethod::Cash)->label(),
                'amount' => (int) $payment->amount,
                'status' => (string) $payment->status,
                'void_reason' => $payment->void_reason === null ? null : (string) $payment->void_reason,
                'items' => $items[(string) $payment->id] ?? [],
            ];
        }

        return [
            'student' => [
                'enrollment_id' => $student['enrollment_id'],
                'name' => $student['name'],
                'class' => $student['class'],
                'student_number' => $student['student_number'],
                'nisn' => $student['nisn'],
                'active' => $student['active'],
            ],
            'invoices' => $invoices,
            'outstanding' => $outstanding,
            'payments' => $history,
        ];
    }
}
