<?php

namespace Modules\Academic\Application\Classroom;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Calendar\SchoolDays;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\HR\Contracts\TeachingSchedule;

/**
 * Jam mengajar guru untuk presensi pegawai HR (PRD-001 §14.1; PRD-002 §16 jadwal pelajaran).
 *
 * Guru pada sebuah tanggal = pengampu mapel yang punya jadwal di kelas aktif pada tahun ajaran yang
 * memuat tanggal itu. Jam hari itu = pelajaran pertama s.d. terakhir di semua lembaga; hari libur
 * kalender akademik lembaga itu tidak dihitung. Hanya membaca.
 */
final class AcademicTeachingSchedule implements TeachingSchedule
{
    public function __construct(
        private readonly SchoolDays $schoolDays,
    ) {}

    public function windows(string $tenantId, array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === [] || $end < $start) {
            return [];
        }

        $entries = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_classes as c', 'c.id', '=', 'e.class_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'e.academic_year_id')
            ->where('e.tenant_id', $tenantId)
            ->whereIn('cs.teacher_employee_id', $employeeIds)
            ->where('c.is_active', true)
            ->where('y.start_date', '<=', $end)
            ->where('y.end_date', '>=', $start)
            ->get(['cs.teacher_employee_id', 'e.academic_year_id', 'e.day_of_week', 'e.start_time', 'e.end_time']);

        if ($entries->isEmpty()) {
            return [];
        }

        $years = AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('id', $entries->pluck('academic_year_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $windows = [];
        $day = CarbonImmutable::parse($start);

        while ($day->toDateString() <= $end) {
            $date = $day->toDateString();

            foreach ($entries as $entry) {
                $year = $years->get((string) $entry->academic_year_id);

                if (! $year instanceof AcademicYear || $date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
                    continue;
                }

                $employeeId = (string) $entry->teacher_employee_id;
                // Guru di tanggal ini; jam diisi bila ada pelajaran yang benar-benar berlangsung.
                $windows[$employeeId][$date] ??= null;

                if ((int) $entry->day_of_week !== $day->dayOfWeekIso || $this->schoolDays->holidayOn($year, $date) !== null) {
                    continue;
                }

                $from = substr((string) $entry->start_time, 0, 5);
                $until = substr((string) $entry->end_time, 0, 5);
                $current = $windows[$employeeId][$date];

                $windows[$employeeId][$date] = $current === null
                    ? ['start' => $from, 'end' => $until]
                    : ['start' => min($current['start'], $from), 'end' => max($current['end'], $until)];
            }

            $day = $day->addDay();
        }

        return $windows;
    }
}
