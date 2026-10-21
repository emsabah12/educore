<?php

namespace Modules\Academic\Application\Attendance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Support\BusinessClock;

/**
 * Rekap presensi harian per kelas (PRD-002 §17 AC-4b): jumlah H/S/I/A dan persentase hadir per
 * siswa untuk satu periode (tahun ajaran, semester, atau bulan).
 *
 * - Hanya sesi presensi harian (per pertemuan direkap terpisah di AC-4c).
 * - Siswa = yang pernah tercatat di kelas dalam periode itu; siswa yang pindah tetap tampil dengan
 *   presensi selama ia di kelas.
 * - Persentase hadir = Hadir ÷ hari yang tercatat untuk siswa itu. Hari tanpa sesi = belum diisi
 *   (tidak dihitung alpa), karena belum ada kalender libur [ASUMSI disetujui].
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-type Period array{value: string, label: string, start: string, end: string}
 * @phpstan-type RecapRow array{enrollment_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, H: int, S: int, I: int, A: int, total: int, percent: float|null}
 */
final class AttendanceRecap
{
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

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
     * @return array{days: int, students: list<RecapRow>, totals: array{H: int, S: int, I: int, A: int}}
     */
    public function recap(SchoolClass $class, string $start, string $end): array
    {
        $days = DB::table('ac_attendance_sessions')
            ->where('class_id', $class->id)
            ->whereNull('schedule_entry_id')
            ->whereBetween('date', [$start, $end])
            ->count();

        $counts = [];
        $rows = DB::table('ac_attendance_records as r')
            ->join('ac_attendance_sessions as s', 's.id', '=', 'r.session_id')
            ->where('s.class_id', $class->id)
            ->whereNull('s.schedule_entry_id')
            ->whereBetween('s.date', [$start, $end])
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
