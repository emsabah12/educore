<?php

namespace Modules\Finance\Application\Savings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Application\Report\Arrears;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Domain\Payment\PaymentStatus;
use Modules\Finance\Domain\Savings\SavingsEntryKind;

/**
 * Laporan tabungan siswa (PRD-005 §6, TB-2): total titipan per lembaga, daftar saldo per siswa, dan rekap bulanan.
 * Hak akses (finance.savings.view di lembaga) dicek pemanggil.
 *
 * Data siswa (nama, kelas berjalan, NIS, status) diambil dari pendaftaran pada transaksi tabungan TERAKHIR siswa itu
 * di lembaga (kontrak Academic `describe`). Pengelompokan kelas sama dengan Tunggakan: kelas berjalan, "Tanpa kelas",
 * atau "Sudah keluar".
 *
 * @phpstan-type OrganizationSavings array{organization_id: string, students: int, total: int}
 * @phpstan-type StudentSavings array{enrollment_id: string, name: string, class: string|null, group: string, student_number: string|null, nisn: string|null, active: bool, balance: int, last_date: string}
 * @phpstan-type ClassSavings array{group: string, label: string, students: int, total: int}
 * @phpstan-type SavingsTransaction array{id: string, entry_date: string, slip_number: string, name: string, class: string|null, kind: string, kind_label: string, method: string|null, amount: int, taken_by: string|null, recorded_by: string|null, status: string, void_reason: string|null}
 * @phpstan-type MonthlySavings array{from: string, to: string, opening: int, deposits: int, withdrawals: int, payments: int, closing: int, deposit_count: int, withdrawal_count: int, payment_count: int, voided_count: int, entries: list<SavingsTransaction>}
 */
final class SavingsReport
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * Total titipan per lembaga (hanya lembaga yang punya saldo), urut total terbesar.
     *
     * @param  list<string>  $organizationIds
     * @return list<OrganizationSavings>
     */
    public function byOrganization(string $tenantId, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $rows = [];

        foreach (DB::table('fn_savings_accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $organizationIds)
            ->where('balance', '>', 0)
            ->groupBy('organization_id')
            ->orderByRaw('SUM(balance) DESC')
            ->orderBy('organization_id')
            ->get(['organization_id', DB::raw('COUNT(*) AS students'), DB::raw('SUM(balance) AS total')]) as $row) {
            $rows[] = ['organization_id' => (string) $row->organization_id, 'students' => (int) $row->students, 'total' => (int) $row->total];
        }

        return $rows;
    }

    /**
     * Saldo per siswa satu lembaga, urut kelompok kelas lalu nama. Kelompok (`classes`) dihitung dari SEMUA rekening
     * lembaga (untuk pilihan saringan); `students` & `total` mengikuti saringan.
     *
     * @param  string|null  $group  null = semua; Arrears::NO_CLASS; Arrears::LEFT; atau nama kelas berjalan
     * @param  string|null  $search  sebagian nama, atau awal NIS / NISN
     * @return array{students: list<StudentSavings>, classes: list<ClassSavings>, total: int}
     */
    public function balances(string $tenantId, string $organizationId, ?string $group = null, ?string $search = null, bool $positiveOnly = true): array
    {
        $accounts = DB::table('fn_savings_accounts as a')
            ->where('a.tenant_id', $tenantId)
            ->where('a.organization_id', $organizationId)
            ->select(['a.id', 'a.balance'])
            ->selectSub(
                DB::table('fn_savings_entries as e')->whereColumn('e.savings_account_id', 'a.id')
                    ->orderByDesc('e.entry_date')->orderByDesc('e.created_at')->orderByDesc('e.id')->limit(1)->select('e.enrollment_id'),
                'enrollment_id',
            )
            ->selectSub(
                DB::table('fn_savings_entries as e')->whereColumn('e.savings_account_id', 'a.id')->where('e.status', PaymentStatus::Valid->value)->selectRaw('MAX(e.entry_date)'),
                'last_date',
            )
            ->get();

        $enrollmentIds = [];

        foreach ($accounts as $account) {
            if ($account->enrollment_id !== null) {
                $enrollmentIds[(string) $account->enrollment_id] = true;
            }
        }

        $described = $this->students->describe($tenantId, array_keys($enrollmentIds));
        $all = [];

        foreach ($accounts as $account) {
            $enrollmentId = (string) $account->enrollment_id;
            $student = $described[$enrollmentId] ?? null;
            $active = $student['active'] ?? false;
            $class = $active ? ($student['class'] ?? null) : null;

            $all[] = [
                'enrollment_id' => $enrollmentId,
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => $class,
                'group' => $active ? ($class ?? Arrears::NO_CLASS) : Arrears::LEFT,
                'student_number' => $student['student_number'] ?? null,
                'nisn' => $student['nisn'] ?? null,
                'active' => $active,
                'balance' => (int) $account->balance,
                'last_date' => $account->last_date === null ? '' : substr((string) $account->last_date, 0, 10),
            ];
        }

        usort($all, fn (array $a, array $b): int => self::compareGroup($a['group'], $b['group'])
            ?: strcasecmp($a['name'], $b['name'])
            ?: strcmp($a['enrollment_id'], $b['enrollment_id']));

        /** @var array<string, ClassSavings> $classes */
        $classes = [];

        foreach ($all as $row) {
            $classes[$row['group']] ??= ['group' => $row['group'], 'label' => Arrears::groupLabel($row['group']), 'students' => 0, 'total' => 0];

            if ($row['balance'] > 0) {
                $classes[$row['group']]['students']++;
                $classes[$row['group']]['total'] += $row['balance'];
            }
        }

        $needle = $search === null ? '' : mb_strtolower(trim($search));
        $students = [];
        $total = 0;

        foreach ($all as $row) {
            if (($positiveOnly && $row['balance'] === 0)
                || ($group !== null && $row['group'] !== $group)
                || ($needle !== '' && ! self::matches($row, $needle))) {
                continue;
            }

            $students[] = $row;
            $total += $row['balance'];
        }

        return ['students' => $students, 'classes' => array_values($classes), 'total' => $total];
    }

    /**
     * Rekap satu bulan (Y-m) satu lembaga: saldo awal & akhir, total per jenis (hanya yang SAH), dan semua transaksi
     * bulan itu termasuk yang dibatalkan (bertanda), urut tanggal lalu nomor slip.
     *
     * @return MonthlySavings
     */
    public function monthly(string $tenantId, string $organizationId, string $month): array
    {
        $first = CarbonImmutable::parse($month.'-01');
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();
        $signed = "SUM(CASE WHEN kind = 'SETOR' THEN amount ELSE -amount END)";

        $opening = (int) DB::table('fn_savings_entries')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('status', PaymentStatus::Valid->value)
            ->where('entry_date', '<', $from)
            ->selectRaw("COALESCE({$signed}, 0) AS total")
            ->value('total');

        $rows = DB::table('fn_savings_entries as e')
            ->leftJoin('memberships as m', 'm.id', '=', 'e.recorded_by_membership_id')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.organization_id', $organizationId)
            ->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')
            ->orderBy('e.slip_year')
            ->orderBy('e.slip_sequence')
            ->get(['e.id', 'e.enrollment_id', 'e.entry_date', 'e.slip_number', 'e.kind', 'e.method', 'e.amount', 'e.taken_by', 'e.status', 'e.void_reason', 'p.name as recorded_by']);

        $enrollmentIds = [];

        foreach ($rows as $row) {
            $enrollmentIds[(string) $row->enrollment_id] = true;
        }

        $described = $this->students->describe($tenantId, array_keys($enrollmentIds));
        $sums = [SavingsEntryKind::Deposit->value => 0, SavingsEntryKind::Withdrawal->value => 0, SavingsEntryKind::InvoicePayment->value => 0];
        $counts = $sums;
        $voided = 0;
        $entries = [];

        foreach ($rows as $row) {
            $kind = SavingsEntryKind::from((string) $row->kind);
            $valid = (string) $row->status === PaymentStatus::Valid->value;
            $student = $described[(string) $row->enrollment_id] ?? null;

            if ($valid) {
                $sums[$kind->value] += (int) $row->amount;
                $counts[$kind->value]++;
            } else {
                $voided++;
            }

            $entries[] = [
                'id' => (string) $row->id,
                'entry_date' => substr((string) $row->entry_date, 0, 10),
                'slip_number' => (string) $row->slip_number,
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => $student['class'] ?? null,
                'kind' => $kind->value,
                'kind_label' => $kind->label(),
                'method' => $row->method === null ? null : (PaymentMethod::tryFrom((string) $row->method) ?? PaymentMethod::Cash)->label(),
                'amount' => (int) $row->amount,
                'taken_by' => $row->taken_by === null ? null : (string) $row->taken_by,
                'recorded_by' => $row->recorded_by === null ? null : (string) $row->recorded_by,
                'status' => (string) $row->status,
                'void_reason' => $row->void_reason === null ? null : (string) $row->void_reason,
            ];
        }

        $deposits = $sums[SavingsEntryKind::Deposit->value];
        $withdrawals = $sums[SavingsEntryKind::Withdrawal->value];
        $payments = $sums[SavingsEntryKind::InvoicePayment->value];

        return [
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'payments' => $payments,
            'closing' => $opening + $deposits - $withdrawals - $payments,
            'deposit_count' => $counts[SavingsEntryKind::Deposit->value],
            'withdrawal_count' => $counts[SavingsEntryKind::Withdrawal->value],
            'payment_count' => $counts[SavingsEntryKind::InvoicePayment->value],
            'voided_count' => $voided,
            'entries' => $entries,
        ];
    }

    /**
     * Pilihan saringan kelas.
     *
     * @param  list<ClassSavings>  $classes
     * @return list<array{value: string, label: string}>
     */
    public static function classOptions(array $classes): array
    {
        $options = [];

        foreach ($classes as $class) {
            $options[] = ['value' => $class['group'], 'label' => $class['label']];
        }

        return $options;
    }

    /**
     * @param  StudentSavings  $row
     */
    private static function matches(array $row, string $needle): bool
    {
        return str_contains(mb_strtolower($row['name']), $needle)
            || ($row['student_number'] !== null && str_starts_with(mb_strtolower($row['student_number']), $needle))
            || ($row['nisn'] !== null && str_starts_with($row['nisn'], $needle));
    }

    /**
     * Urut kelas alami ("7A" < "7B" < "10A"), lalu tanpa kelas, lalu sudah keluar (sama dengan Tunggakan).
     */
    private static function compareGroup(string $a, string $b): int
    {
        $rank = fn (string $group): int => match ($group) {
            Arrears::NO_CLASS => 1,
            Arrears::LEFT => 2,
            default => 0,
        };

        return $rank($a) <=> $rank($b) ?: strnatcasecmp($a, $b);
    }
}
