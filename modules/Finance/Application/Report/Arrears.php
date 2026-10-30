<?php

namespace Modules\Finance\Application\Report;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Finance\Domain\Invoice\InvoiceStatus;

/**
 * Tunggakan siswa (PRD-003 §3 FN-4, §6; rincian FN-4a §17). Hak akses dicek pemanggil.
 *
 * Tunggakan = sisa (nominal − dibayar) tagihan BELUM LUNAS yang jatuh temponya sudah lewat (jatuh tempo
 * sebelum hari ini) — sama dengan tanda "Lewat jatuh tempo" di Kasir. Semua tahun ajaran dihitung, termasuk
 * siswa yang sudah keluar. Kelas = kelas berjalan siswa (kontrak Academic `describe`); siswa yang sudah keluar
 * tidak punya kelas berjalan sehingga dikelompokkan sendiri ("Sudah keluar").
 *
 * @phpstan-type OrganizationTotal array{organization_id: string, students: int, invoices: int, total: int}
 * @phpstan-type StudentArrears array{enrollment_id: string, name: string, class: string|null, group: string, student_number: string|null, nisn: string|null, active: bool, invoices: int, total: int, oldest_due: string}
 * @phpstan-type ClassArrears array{group: string, label: string, students: int, invoices: int, total: int}
 * @phpstan-type InvoiceArrears array{enrollment_id: string, description: string, due_date: string, amount: int, paid_amount: int, outstanding: int}
 */
final class Arrears
{
    /** Kelompok siswa aktif yang belum masuk kelas. */
    public const NO_CLASS = '-';

    /** Kelompok siswa yang pendaftarannya sudah ditutup (keluar, pindah, lulus). */
    public const LEFT = 'keluar';

    public const PER_PAGE = 50;

    public function __construct(
        private readonly EnrolledStudents $students,
    ) {}

    /**
     * Ringkasan per lembaga (hanya lembaga yang punya tunggakan), urut tunggakan terbesar.
     *
     * @param  list<string>  $organizationIds
     * @return list<OrganizationTotal>
     */
    public function byOrganization(string $tenantId, array $organizationIds, string $today): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $totals = [];

        foreach (DB::table('fn_invoices')
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $organizationIds)
            ->where('status', InvoiceStatus::Open->value)
            ->where('due_date', '<', $today)
            ->groupBy('organization_id')
            ->orderByRaw('SUM(amount - paid_amount) DESC')
            ->orderBy('organization_id')
            ->get([
                'organization_id',
                DB::raw('COUNT(DISTINCT enrollment_id) AS students'),
                DB::raw('COUNT(*) AS invoices'),
                DB::raw('SUM(amount - paid_amount) AS total'),
            ]) as $row) {
            $totals[] = [
                'organization_id' => (string) $row->organization_id,
                'students' => (int) $row->students,
                'invoices' => (int) $row->invoices,
                'total' => (int) $row->total,
            ];
        }

        return $totals;
    }

    /**
     * Tunggakan satu lembaga: per siswa (urut kelompok lalu nama), per kelompok kelas, dan rincian tagihannya.
     * $group: null = semua; NO_CLASS = aktif tanpa kelas; LEFT = sudah keluar; selain itu nama kelas berjalan.
     *
     * @return array{students: list<StudentArrears>, classes: list<ClassArrears>, invoices: list<InvoiceArrears>, total: int}
     */
    public function forOrganization(string $tenantId, string $organizationId, string $today, ?string $group = null): array
    {
        $items = DB::table('fn_invoices')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('status', InvoiceStatus::Open->value)
            ->where('due_date', '<', $today)
            ->orderBy('due_date')
            ->orderByRaw('period NULLS LAST')
            ->orderBy('id')
            ->get(['enrollment_id', 'description', 'due_date', 'amount', 'paid_amount']);

        $enrollmentIds = [];

        foreach ($items as $item) {
            $enrollmentIds[(string) $item->enrollment_id] = true;
        }

        $described = $this->students->describe($tenantId, array_keys($enrollmentIds));

        /** @var array<string, StudentArrears> $students */
        $students = [];
        $invoices = [];

        foreach ($items as $item) {
            $enrollmentId = (string) $item->enrollment_id;
            $student = $described[$enrollmentId] ?? null;
            $active = $student['active'] ?? false;
            $studentClass = $active ? ($student['class'] ?? null) : null;
            $studentGroup = $active ? ($studentClass ?? self::NO_CLASS) : self::LEFT;

            if ($group !== null && $studentGroup !== $group) {
                continue;
            }

            $outstanding = (int) $item->amount - (int) $item->paid_amount;
            $dueDate = substr((string) $item->due_date, 0, 10);

            if (! isset($students[$enrollmentId])) {
                $students[$enrollmentId] = [
                    'enrollment_id' => $enrollmentId,
                    'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                    'class' => $studentClass,
                    'group' => $studentGroup,
                    'student_number' => $student['student_number'] ?? null,
                    'nisn' => $student['nisn'] ?? null,
                    'active' => $active,
                    'invoices' => 0,
                    'total' => 0,
                    // Tagihan diurutkan jatuh tempo, jadi yang pertama = terlama.
                    'oldest_due' => $dueDate,
                ];
            }

            $students[$enrollmentId]['invoices']++;
            $students[$enrollmentId]['total'] += $outstanding;

            $invoices[] = [
                'enrollment_id' => $enrollmentId,
                'description' => (string) $item->description,
                'due_date' => $dueDate,
                'amount' => (int) $item->amount,
                'paid_amount' => (int) $item->paid_amount,
                'outstanding' => $outstanding,
            ];
        }

        $sorted = array_values($students);
        usort($sorted, fn (array $a, array $b): int => self::compareGroup($a['group'], $b['group'])
            ?: strcasecmp($a['name'], $b['name'])
            ?: strcmp($a['enrollment_id'], $b['enrollment_id']));

        // Rincian mengikuti urutan siswa, lalu jatuh tempo.
        $position = array_flip(array_column($sorted, 'enrollment_id'));
        usort($invoices, fn (array $a, array $b): int => $position[$a['enrollment_id']] <=> $position[$b['enrollment_id']]);

        /** @var array<string, ClassArrears> $classes */
        $classes = [];
        $total = 0;

        foreach ($sorted as $student) {
            $key = $student['group'];
            $classes[$key] ??= ['group' => $key, 'label' => self::groupLabel($key), 'students' => 0, 'invoices' => 0, 'total' => 0];
            $classes[$key]['students']++;
            $classes[$key]['invoices'] += $student['invoices'];
            $classes[$key]['total'] += $student['total'];
            $total += $student['total'];
        }

        return [
            'students' => $sorted,
            'classes' => array_values($classes),
            'invoices' => $invoices,
            'total' => $total,
        ];
    }

    /**
     * Pilihan saringan kelas: kelompok siswa yang menunggak di lembaga ini.
     *
     * @param  list<ClassArrears>  $classes
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

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            self::NO_CLASS => 'Tanpa kelas',
            self::LEFT => 'Sudah keluar',
            default => $group,
        };
    }

    /**
     * Urut kelas alami ("7A" < "7B" < "10A"), lalu tanpa kelas, lalu sudah keluar.
     */
    private static function compareGroup(string $a, string $b): int
    {
        $rank = fn (string $group): int => match ($group) {
            self::NO_CLASS => 1,
            self::LEFT => 2,
            default => 0,
        };

        return $rank($a) <=> $rank($b) ?: strnatcasecmp($a, $b);
    }
}
