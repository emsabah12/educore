<?php

namespace Modules\HR\Application\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Attendance\AttendanceStatus;
use Modules\HR\Domain\Leave\LeaveStatus;
use stdClass;

/**
 * Rekap presensi pegawai (PRD-001 §14.4, HR-4c). Hanya membaca; hak akses dicek pemanggil.
 *
 * Aturan hitung [ASUMSI, 2026-10-24]:
 * - Periode = satu bulan, dihitung sampai hari ini untuk bulan berjalan.
 * - Rekap lembaga: pegawai yang penempatan UTAMA-nya di lembaga itu pada sebagian bulan; yang dihitung
 *   hanya hari-hari selama penempatan itu.
 * - Hari wajib = hari dengan jam wajib (§14.1). Cuti disetujui di hari wajib → Cuti (catatan
 *   presensinya, bila ada, diabaikan). Hari wajib tanpa catatan & bukan cuti → Belum diisi.
 * - Catatan di hari tidak wajib tetap dihitung statusnya (mis. Hadir di hari libur).
 * - Terlambat & pulang cepat: jumlah kali dan total menit.
 *
 * @phpstan-import-type Expected from ExpectedHours
 *
 * @phpstan-type Counts array{required: int, present: int, late: int, late_minutes: int, early: int, early_minutes: int, sick: int, permit: int, duty: int, leave: int, absent: int, empty: int}
 * @phpstan-type EmployeeCounts array{employee_id: string, name: string, employee_number: string|null, required: int, present: int, late: int, late_minutes: int, early: int, early_minutes: int, sick: int, permit: int, duty: int, leave: int, absent: int, empty: int}
 * @phpstan-type DayDetail array{date: string, employee_id: string, name: string, expected: string, status: string, check_in: string|null, check_out: string|null, late: int, early: int}
 */
final class AttendanceRecap
{
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly ExpectedHours $expected,
    ) {}

    /**
     * "2026-10" bila sah dan tidak di masa depan; selain itu bulan berjalan.
     */
    public static function month(mixed $requested): string
    {
        $current = BusinessClock::today()->format('Y-m');

        return is_string($requested) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1 && $requested <= $current ? $requested : $current;
    }

    public static function monthLabel(string $month): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));

        return self::MONTHS[$number].' '.$year;
    }

    /**
     * Rentang hitung bulan itu: [awal bulan, min(akhir bulan, hari ini)].
     *
     * @return array{0: string, 1: string}
     */
    public static function range(string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01');
        $end = $start->endOfMonth()->toDateString();
        $today = BusinessClock::todayString();

        return [$start->toDateString(), $end < $today ? $end : $today];
    }

    /**
     * Rekap satu lembaga sebulan.
     *
     * @return array{rows: list<EmployeeCounts>, totals: Counts, details: list<DayDetail>}
     */
    public function forOrganization(string $tenantId, string $organizationId, string $month): array
    {
        [$start, $end] = self::range($month);

        $placements = DB::table('hr_placements as pl')
            ->join('hr_employees as e', 'e.id', '=', 'pl.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('pl.tenant_id', $tenantId)
            ->where('pl.organization_id', $organizationId)
            ->where('pl.is_primary', true)
            ->where('pl.start_date', '<=', $end)
            ->where(fn (Builder $query) => $query->whereNull('pl.end_date')->orWhere('pl.end_date', '>=', $start))
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.employee_number', 'p.name', 'pl.start_date', 'pl.end_date']);

        /** @var array<string, array{name: string, number: string|null, from: string, until: string}> $employees */
        $employees = [];

        foreach ($placements as $placement) {
            $id = (string) $placement->id;
            $from = max($start, substr((string) $placement->start_date, 0, 10));
            $until = $placement->end_date === null ? $end : min($end, substr((string) $placement->end_date, 0, 10));
            // Penempatan utama di lembaga yang sama bisa terpecah (mis. mutasi pergi lalu kembali).
            $employees[$id] = isset($employees[$id])
                ? ['name' => $employees[$id]['name'], 'number' => $employees[$id]['number'], 'from' => min($employees[$id]['from'], $from), 'until' => max($employees[$id]['until'], $until)]
                : ['name' => (string) $placement->name, 'number' => $placement->employee_number === null ? null : (string) $placement->employee_number, 'from' => $from, 'until' => $until];
        }

        $rows = [];
        $details = [];
        $totals = self::emptyCounts();

        if ($employees !== [] && $start <= $end) {
            $expected = $this->expected->forEmployees($tenantId, array_fill_keys(array_keys($employees), $organizationId), $start, $end);
            $records = $this->records(array_keys($employees), $start, $end);
            $leaves = $this->leaveDays(array_keys($employees), $start, $end);

            foreach ($employees as $id => $employee) {
                [$counts, $days] = $this->count($expected[$id] ?? [], $records[$id] ?? [], $leaves[$id] ?? [], $employee['from'], $employee['until']);
                $rows[] = ['employee_id' => $id, 'name' => $employee['name'], 'employee_number' => $employee['number']] + $counts;

                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                }

                foreach ($days as $day) {
                    $details[] = ['employee_id' => $id, 'name' => $employee['name']] + $day;
                }
            }
        }

        usort($details, fn (array $a, array $b): int => [$a['date'], mb_strtolower($a['name'])] <=> [$b['date'], mb_strtolower($b['name'])]);

        return ['rows' => $rows, 'totals' => $totals, 'details' => $details];
    }

    /**
     * Ringkasan satu pegawai pada bulan itu, di penempatan utamanya sekarang (Presensi saya & detail
     * pegawai). Null bila tidak punya penempatan utama berjalan.
     *
     * @return array{month: string, label: string, until: string, counts: Counts}|null
     */
    public function forEmployee(string $tenantId, string $employeeId, ?string $month = null): ?array
    {
        $organizationId = DB::table('hr_placements')
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->whereNull('end_date')
            ->value('organization_id');

        if (! is_string($organizationId)) {
            return null;
        }

        $month = self::month($month);
        [$start, $end] = self::range($month);
        $expected = $this->expected->forEmployee($tenantId, $employeeId, $organizationId, $start, $end);
        [$counts] = $this->count($expected, $this->records([$employeeId], $start, $end)[$employeeId] ?? [], $this->leaveDays([$employeeId], $start, $end)[$employeeId] ?? [], $start, $end);

        return ['month' => $month, 'label' => self::monthLabel($month), 'until' => $end, 'counts' => $counts];
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, Expected>  $expected
     * @param  array<string, stdClass>  $records  tanggal → catatan
     * @param  array<string, true>  $leaves  tanggal cuti disetujui
     * @return array{0: Counts, 1: list<array{date: string, expected: string, status: string, check_in: string|null, check_out: string|null, late: int, early: int}>}
     */
    private function count(array $expected, array $records, array $leaves, string $from, string $until): array
    {
        $counts = self::emptyCounts();
        $days = [];

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $until; $day = $day->addDay()) {
            $date = $day->toDateString();
            $plan = $expected[$date] ?? null;
            $required = $plan !== null && $plan['required'];
            $record = $records[$date] ?? null;

            if ($required) {
                $counts['required']++;
            }

            if ($required && isset($leaves[$date])) {
                $counts['leave']++;
                $days[] = self::detail($date, $plan, 'Cuti', null);

                continue;
            }

            if ($record === null) {
                if ($required) {
                    $counts['empty']++;
                    $days[] = self::detail($date, $plan, 'Belum diisi', null);
                }

                continue;
            }

            $status = AttendanceStatus::from((string) $record->status);

            match ($status) {
                AttendanceStatus::Present => $counts['present']++,
                AttendanceStatus::Sick => $counts['sick']++,
                AttendanceStatus::Permit => $counts['permit']++,
                AttendanceStatus::Absent => $counts['absent']++,
                AttendanceStatus::Duty => $counts['duty']++,
            };

            if ((int) $record->late_minutes > 0) {
                $counts['late']++;
                $counts['late_minutes'] += (int) $record->late_minutes;
            }

            if ((int) $record->early_minutes > 0) {
                $counts['early']++;
                $counts['early_minutes'] += (int) $record->early_minutes;
            }

            $days[] = self::detail($date, $plan, $status->label(), $record);
        }

        return [$counts, $days];
    }

    /**
     * @param  Expected|null  $plan
     * @return array{date: string, expected: string, status: string, check_in: string|null, check_out: string|null, late: int, early: int}
     */
    private static function detail(string $date, ?array $plan, string $status, ?stdClass $record): array
    {
        $time = fn (mixed $value): ?string => $value === null ? null : substr((string) $value, 0, 5);

        return [
            'date' => $date,
            'expected' => $plan === null ? '—' : $plan['label'],
            'status' => $status,
            'check_in' => $record === null ? null : $time($record->check_in),
            'check_out' => $record === null ? null : $time($record->check_out),
            'late' => $record === null ? 0 : (int) $record->late_minutes,
            'early' => $record === null ? 0 : (int) $record->early_minutes,
        ];
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, stdClass>> pegawai → tanggal → catatan
     */
    private function records(array $employeeIds, string $start, string $end): array
    {
        $records = [];

        foreach (DB::table('hr_attendances')->whereIn('employee_id', $employeeIds)->whereBetween('date', [$start, $end])->get() as $row) {
            $records[(string) $row->employee_id][substr((string) $row->date, 0, 10)] = $row;
        }

        return $records;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, true>> pegawai → tanggal cuti disetujui
     */
    private function leaveDays(array $employeeIds, string $start, string $end): array
    {
        $days = [];

        $rows = DB::table('hr_leave_requests')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveStatus::Approved->value)
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->get(['employee_id', 'start_date', 'end_date']);

        foreach ($rows as $row) {
            $from = max($start, substr((string) $row->start_date, 0, 10));
            $until = min($end, substr((string) $row->end_date, 0, 10));

            for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $until; $day = $day->addDay()) {
                $days[(string) $row->employee_id][$day->toDateString()] = true;
            }
        }

        return $days;
    }

    /**
     * @return Counts
     */
    private static function emptyCounts(): array
    {
        return ['required' => 0, 'present' => 0, 'late' => 0, 'late_minutes' => 0, 'early' => 0, 'early_minutes' => 0, 'sick' => 0, 'permit' => 0, 'duty' => 0, 'leave' => 0, 'absent' => 0, 'empty' => 0];
    }
}
