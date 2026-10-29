<?php

namespace Modules\Finance\Application\Invoice;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Domain\Invoice\InvoiceStatus;

/**
 * Daftar tagihan satu lembaga × tahun ajaran dengan saringan (PRD-003 §6, FN-2a). Hak akses dicek pemanggil.
 *
 * Saringan: jenis biaya, bulan (tagihan bulanan), status, dan nama/NISN/nomor induk siswa (hanya siswa
 * yang masih aktif di lembaga — dicari lewat kontrak Academic). Urut nama siswa, lalu bulan.
 *
 * @phpstan-type InvoiceRow array{id: string, enrollment_id: string, name: string, class: string|null, student_number: string|null, active: bool, fee_type: string, description: string, period: string|null, base_amount: int, discount_amount: int, discount_label: string|null, amount: int, paid_amount: int, due_date: string, status: string, status_label: string, overdue: bool, void_reason: string|null}
 * @phpstan-type Filters array{type: string|null, month: string|null, status: string|null, q: string|null}
 */
final class InvoiceList
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * @param  Filters  $filters
     * @return array{rows: list<InvoiceRow>, page: int, last_page: int, total: int, sums: array{amount: int, paid: int, outstanding: int}}
     */
    public function list(string $tenantId, string $organizationId, string $yearId, array $filters, int $page, string $today): array
    {
        $query = $this->query($tenantId, $organizationId, $yearId, $filters);

        if ($query === null) {
            return ['rows' => [], 'page' => 1, 'last_page' => 1, 'total' => 0, 'sums' => ['amount' => 0, 'paid' => 0, 'outstanding' => 0]];
        }

        // Satu lembaga × tahun ajaran (paling banyak ribuan baris): diurutkan nama siswa di PHP karena nama
        // berasal dari Academic (kontrak), bukan dari tabel Finance.
        $items = $query->get([
            'i.id', 'i.enrollment_id', 'i.description', 'i.period', 'i.base_amount', 'i.discount_amount', 'i.discount_label',
            'i.amount', 'i.paid_amount', 'i.due_date', 'i.status', 'i.void_reason', 'i.created_at', 't.name as fee_type',
        ]);

        $enrollmentIds = [];

        foreach ($items as $item) {
            $enrollmentIds[] = (string) $item->enrollment_id;
        }

        $students = $this->students->describe($tenantId, array_values(array_unique($enrollmentIds)));
        $rows = [];
        $sumAmount = 0;
        $sumPaid = 0;

        foreach ($items as $item) {
            $student = $students[(string) $item->enrollment_id] ?? null;
            $status = InvoiceStatus::tryFrom((string) $item->status) ?? InvoiceStatus::Open;
            $dueDate = substr((string) $item->due_date, 0, 10);

            if ($status !== InvoiceStatus::Void) {
                $sumAmount += (int) $item->amount;
                $sumPaid += (int) $item->paid_amount;
            }

            $rows[] = [
                'id' => (string) $item->id,
                'enrollment_id' => (string) $item->enrollment_id,
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => $student['class'] ?? null,
                'student_number' => $student['student_number'] ?? null,
                'active' => $student['active'] ?? false,
                'fee_type' => (string) $item->fee_type,
                'description' => (string) $item->description,
                'period' => $item->period === null ? null : substr((string) $item->period, 0, 7),
                'base_amount' => (int) $item->base_amount,
                'discount_amount' => (int) $item->discount_amount,
                'discount_label' => $item->discount_label === null ? null : (string) $item->discount_label,
                'amount' => (int) $item->amount,
                'paid_amount' => (int) $item->paid_amount,
                'due_date' => $dueDate,
                'status' => $status->value,
                'status_label' => $status->label(),
                'overdue' => $status === InvoiceStatus::Open && $dueDate < $today,
                'void_reason' => $item->void_reason === null ? null : (string) $item->void_reason,
                'sort' => [mb_strtolower($student['name'] ?? ''), (string) $item->enrollment_id, $item->period === null ? '9999' : (string) $item->period, (string) $item->created_at, (string) $item->id],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $pageRows = [];

        foreach (array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE) as $row) {
            unset($row['sort']);
            $pageRows[] = $row;
        }

        return [
            'rows' => $pageRows,
            'page' => $page,
            'last_page' => $lastPage,
            'total' => $total,
            'sums' => ['amount' => $sumAmount, 'paid' => $sumPaid, 'outstanding' => $sumAmount - $sumPaid],
        ];
    }

    /**
     * @param  Filters  $filters
     */
    private function query(string $tenantId, string $organizationId, string $yearId, array $filters): ?Builder
    {
        $query = DB::table('fn_invoices as i')
            ->join('fn_fee_types as t', 't.id', '=', 'i.fee_type_id')
            ->where('i.tenant_id', $tenantId)
            ->where('i.organization_id', $organizationId)
            ->where('i.academic_year_id', $yearId);

        if ($filters['type'] !== null) {
            $query->where('i.fee_type_id', $filters['type']);
        }

        if ($filters['month'] !== null) {
            $query->where('i.period', $filters['month'].'-01');
        }

        if ($filters['status'] !== null) {
            $query->where('i.status', $filters['status']);
        }

        if ($filters['q'] !== null) {
            $ids = array_column($this->students->search($tenantId, $organizationId, $filters['q'], 50), 'enrollment_id');

            if ($ids === []) {
                return null;
            }

            $query->whereIn('i.enrollment_id', $ids);
        }

        return $query;
    }
}
