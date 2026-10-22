<?php

namespace Modules\Academic\Application\Attendance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Calendar\SchoolDays;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Support\BusinessClock;

/**
 * Rekap presensi harian per kelas (PRD-002 §17 AC-4b): jumlah H/S/I/A dan persentase hadir per
 * siswa untuk satu periode (tahun ajaran, semester, atau bulan).
 *
 * - Harian dan per pertemuan (AC-4c) direkap terpisah; per pertemuan bisa disaring per mapel.
 * - Siswa = yang pernah tercatat di kelas dalam periode itu; siswa yang pindah tetap tampil dengan
 *   presensi selama ia di kelas.
 * - Persentase hadir = Hadir ÷ hari/pertemuan yang tercatat untuk siswa itu. Hari tanpa sesi = belum diisi
 *   (tidak dihitung alpa) [ASUMSI disetujui].
 * - Rekap harian juga menyebut hari efektif periode itu dan hari efektif yang belum diisi sampai hari
 *   ini (PRD-002 §18 P4); hari libur yang tetap diisi tidak dihitung hari efektif.
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-type Period array{value: string, label: string, start: string, end: string}
 * @phpstan-type RecapRow array{enrollment_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, H: int, S: int, I: int, A: int, total: int, percent: float|null}
 */
final class AttendanceRecap
{
    /** Paling banyak sekian tanggal belum diisi yang disebut satu per satu. */
    public const MAX_UNFILLED_LISTED = 31;

    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly SchoolDays $schoolDays,
    ) {}

    /**
     * Hari efektif presensi harian kelas pada periode (PRD-002 §18 P4).
     *
     * - days: hari efektif di periode itu (di dalam tahun ajaran);
     * - due: hari efektif sampai hari ini;
     * - unfilled: hari efektif sampai hari ini yang belum punya presensi harian;
     * - unfilled_dates: tanggal-tanggalnya, paling banyak MAX_UNFILLED_LISTED yang terbaru.
     *
     * @return array{days: int, due: int, unfilled: int, unfilled_dates: list<string>}
     */
    public function effective(SchoolClass $class, string $start, string $end): array
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $dates = $this->schoolDays->effectiveDates($year, $start, $end);
        $today = BusinessClock::todayString();
        $due = array_values(array_filter($dates, fn (string $date): bool => $date <= $today));

        $filled = DB::table('ac_attendance_sessions')
            ->where('class_id', $class->id)
            ->whereNull('class_subject_id')
            ->whereBetween('date', [$start, $end])
            ->pluck('date')
            ->map(fn (mixed $date): string => substr((string) $date, 0, 10))
            ->flip()
            ->all();

        $unfilled = array_values(array_filter($due, fn (string $date): bool => ! isset($filled[$date])));

        return [
            'days' => count($dates),
            'due' => count($due),
            'unfilled' => count($unfilled),
            'unfilled_dates' => array_slice(array_reverse($unfilled), 0, self::MAX_UNFILLED_LISTED),
        ];
    }

    /**
     * Pilihan periode: seluruh tahun ajaran, tiap semester, lalu tiap bulan yang sudah mulai.
     *
     * @return list<Period>
     */
    public function periods(SchoolClass $class): array
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $today = BusinessClock::todayString();
        $periods = [[
            'value' => 'tahun',
            'label' => "Tahun ajaran {$year->name}",
            'start' => $year->start_date->toDateString(),
            'end' => $year->end_date->toDateString(),
        ]];

        $terms = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $year->id)->orderBy('sequence')->get();

        foreach ($terms as $term) {
            $periods[] = [
                'value' => 'semester-'.$term->sequence,
                'label' => $term->name,
                'start' => $term->start_date->toDateString(),
                'end' => $term->end_date->toDateString(),
            ];
        }

        $month = $year->start_date->startOfMonth();

        while ($month->toDateString() <= $year->end_date->toDateString() && $month->toDateString() <= $today) {
            $periods[] = [
                'value' => 'bulan-'.$month->format('Y-m'),
                'label' => self::MONTHS[$month->month].' '.$month->year,
                'start' => max($month->toDateString(), $year->start_date->toDateString()),
                'end' => min($month->endOfMonth()->toDateString(), $year->end_date->toDateString()),
            ];
            $month = $month->addMonth();
        }

        return $periods;
    }

    /**
     * Periode dari URL bila ada di pilihan; selain itu bulan berjalan (bila ada), lalu tahun ajaran.
     *
     * @param  list<Period>  $periods
     * @return Period
     */
    public static function pick(array $periods, mixed $requested): array
    {
        $byValue = [];

        foreach ($periods as $period) {
            $byValue[$period['value']] = $period;
        }

        if (is_string($requested) && isset($byValue[$requested])) {
            return $byValue[$requested];
        }

        return $byValue['bulan-'.BusinessClock::today()->format('Y-m')] ?? $periods[0];
    }

    /**
     * Mapel kelas untuk saringan rekap per pertemuan (AC-4c), urut nama; bisa dibatasi ke daftar id.
     *
     * @param  list<string>|null  $onlyIds
     * @return list<array{value: string, label: string}>
     */
    public function subjectOptions(SchoolClass $class, ?array $onlyIds = null): array
    {
        $query = DB::table('ac_class_subjects as cs')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.class_id', $class->id)
            ->orderByRaw('lower(s.name)');

        if ($onlyIds !== null) {
            $query->whereIn('cs.id', $onlyIds);
        }

        $options = [];

        foreach ($query->get(['cs.id', 's.name']) as $row) {
            $options[] = ['value' => (string) $row->id, 'label' => (string) $row->name];
        }

        return $options;
    }

    /**
     * @param  bool  $lesson  true = sesi per pertemuan (AC-4c), false = sesi harian
     * @param  list<string>|null  $classSubjectIds  saringan mapel kelas (hanya per pertemuan)
     * @return array{days: int, students: list<RecapRow>, totals: array{H: int, S: int, I: int, A: int}}
     */
    public function recap(SchoolClass $class, string $start, string $end, bool $lesson = false, ?array $classSubjectIds = null): array
    {
        $sessions = function (Builder $query, string $alias) use ($class, $start, $end, $lesson, $classSubjectIds): void {
            $query->where("{$alias}.class_id", $class->id)->whereBetween("{$alias}.date", [$start, $end]);

            if (! $lesson) {
                $query->whereNull("{$alias}.class_subject_id");
            } elseif ($classSubjectIds !== null) {
                $query->whereIn("{$alias}.class_subject_id", $classSubjectIds);
            } else {
                $query->whereNotNull("{$alias}.class_subject_id");
            }
        };

        $daysQuery = DB::table('ac_attendance_sessions as s');
        $sessions($daysQuery, 's');
        $days = $daysQuery->count();

        $counts = [];
        $recordsQuery = DB::table('ac_attendance_records as r')
            ->join('ac_attendance_sessions as s', 's.id', '=', 'r.session_id');
        $sessions($recordsQuery, 's');
        $rows = $recordsQuery
            ->groupBy('r.enrollment_id', 'r.status')
            ->selectRaw('r.enrollment_id, r.status, count(*) AS total')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->enrollment_id][(string) $row->status] = (int) $row->total;
        }

        $members = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as st', 'st.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 'st.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $class->id)
            ->where('cm.start_date', '<=', $end)
            ->where(function (Builder $query) use ($start): void {
                $query->whereNull('cm.end_date')->orWhere('cm.end_date', '>', $start);
            })
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.student_number', 'st.nisn', 'p.name', 'p.gender']);

        $students = [];
        $totals = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
        $seen = [];

        foreach ($members as $member) {
            $id = (string) $member->id;

            // Siswa yang keluar lalu masuk lagi ke kelas yang sama cukup sekali.
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $mine = $counts[$id] ?? [];
            $present = $mine['H'] ?? 0;
            $sick = $mine['S'] ?? 0;
            $permitted = $mine['I'] ?? 0;
            $absent = $mine['A'] ?? 0;
            $total = $present + $sick + $permitted + $absent;

            $totals['H'] += $present;
            $totals['S'] += $sick;
            $totals['I'] += $permitted;
            $totals['A'] += $absent;

            $students[] = [
                'enrollment_id' => $id,
                'name' => (string) $member->name,
                'gender' => $member->gender === null ? null : (string) $member->gender,
                'nisn' => $member->nisn === null ? null : (string) $member->nisn,
                'student_number' => $member->student_number === null ? null : (string) $member->student_number,
                'H' => $present,
                'S' => $sick,
                'I' => $permitted,
                'A' => $absent,
                'total' => $total,
                'percent' => $total === 0 ? null : round($present * 100 / $total, 1),
            ];
        }

        return ['days' => $days, 'students' => $students, 'totals' => $totals];
    }
}
