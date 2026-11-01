<?php

namespace Modules\Finance\Application\Savings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Application\Payment\Terbilang;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Domain\Savings\SavingsEntryKind;

/**
 * Data slip setoran/penarikan tabungan (PRD-005 §4, TB-1) untuk halaman cetak. Hak akses dicek pemanggil.
 *
 * @phpstan-type Slip array{id: string, organization_id: string, enrollment_id: string, tenant: string, organization: string, slip_number: string, entry_date: string, kind: string, kind_label: string, method: string|null, amount: int, amount_words: string, balance_after: int, taken_by: string|null, note: string|null, status: string, void_reason: string|null, recorded_by: string|null, student: array{name: string, class: string|null, student_number: string|null, nisn: string|null}}
 */
final class SavingsSlips
{
    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * @return Slip|null
     */
    public function find(string $tenantId, string $entryId): ?array
    {
        if (! Str::isUuid($entryId)) {
            return null;
        }

        $entry = DB::table('fn_savings_entries as e')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->join('tenants as t', 't.id', '=', 'e.tenant_id')
            ->leftJoin('memberships as m', 'm.id', '=', 'e.recorded_by_membership_id')
            ->leftJoin('persons as rp', 'rp.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.id', $entryId)
            ->first([
                'e.id', 'e.organization_id', 'e.enrollment_id', 'e.slip_number', 'e.entry_date', 'e.kind', 'e.method', 'e.amount', 'e.balance_after',
                'e.taken_by', 'e.note', 'e.status', 'e.void_reason', 'o.name as organization', 't.name as tenant', 'rp.name as recorded_by',
            ]);

        if ($entry === null) {
            return null;
        }

        $kind = SavingsEntryKind::from((string) $entry->kind);
        $student = $this->students->describe($tenantId, [(string) $entry->enrollment_id])[(string) $entry->enrollment_id] ?? null;

        return [
            'id' => (string) $entry->id,
            'organization_id' => (string) $entry->organization_id,
            'enrollment_id' => (string) $entry->enrollment_id,
            'tenant' => (string) $entry->tenant,
            'organization' => (string) $entry->organization,
            'slip_number' => (string) $entry->slip_number,
            'entry_date' => substr((string) $entry->entry_date, 0, 10),
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'method' => $entry->method === null ? null : (PaymentMethod::tryFrom((string) $entry->method) ?? PaymentMethod::Cash)->label(),
            'amount' => (int) $entry->amount,
            'amount_words' => Terbilang::rupiah((int) $entry->amount),
            'balance_after' => (int) $entry->balance_after,
            'taken_by' => $entry->taken_by === null ? null : (string) $entry->taken_by,
            'note' => $entry->note === null ? null : (string) $entry->note,
            'status' => (string) $entry->status,
            'void_reason' => $entry->void_reason === null ? null : (string) $entry->void_reason,
            'recorded_by' => $entry->recorded_by === null ? null : (string) $entry->recorded_by,
            'student' => [
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => $student['class'] ?? null,
                'student_number' => $student['student_number'] ?? null,
                'nisn' => $student['nisn'] ?? null,
            ],
        ];
    }
}
