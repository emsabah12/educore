<?php

namespace Modules\Finance\Application\Discount;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Application\Fee\Rupiah;

/**
 * Daftar potongan/beasiswa satu lembaga × tahun ajaran (PRD-003 §6, FN-1b). Hak akses dicek pemanggil.
 *
 * @phpstan-type DiscountRow array{id: string, enrollment_id: string, name: string, nisn: string|null, student_number: string|null, class: string|null, active: bool, fee_type: string, kind: string, value: int, label: string, reason: string}
 */
final class DiscountOverview
{
    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * Urut nama siswa, lalu nama jenis biaya.
     *
     * @return list<DiscountRow>
     */
    public function list(string $tenantId, string $organizationId, string $yearId): array
    {
        $rows = DB::table('fn_discounts as d')
            ->join('fn_fee_types as t', 't.id', '=', 'd.fee_type_id')
            ->where('d.tenant_id', $tenantId)
            ->where('d.organization_id', $organizationId)
            ->where('d.academic_year_id', $yearId)
            ->get(['d.id', 'd.enrollment_id', 'd.percent', 'd.amount', 'd.reason', 't.name as fee_type']);

        $enrollmentIds = [];

        foreach ($rows as $row) {
            $enrollmentIds[] = (string) $row->enrollment_id;
        }

        $students = $this->students->describe($tenantId, array_values(array_unique($enrollmentIds)));
        $list = [];

        foreach ($rows as $row) {
            $student = $students[(string) $row->enrollment_id] ?? null;
            $percent = $row->percent === null ? null : (int) $row->percent;
            $amount = $row->amount === null ? 0 : (int) $row->amount;

            $list[] = [
                'id' => (string) $row->id,
                'enrollment_id' => (string) $row->enrollment_id,
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'nisn' => $student['nisn'] ?? null,
                'student_number' => $student['student_number'] ?? null,
                'class' => $student['class'] ?? null,
                'active' => $student['active'] ?? false,
                'fee_type' => (string) $row->fee_type,
                'kind' => $percent !== null ? ManageDiscounts::KIND_PERCENT : ManageDiscounts::KIND_AMOUNT,
                'value' => $percent ?? $amount,
                'label' => $percent !== null ? "{$percent}%" : Rupiah::format($amount),
                'reason' => (string) $row->reason,
            ];
        }

        usort($list, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['enrollment_id'], $a['fee_type']] <=> [mb_strtolower($b['name']), $b['enrollment_id'], $b['fee_type']]);

        return $list;
    }
}
