<?php

namespace Modules\Academic\Application\Calendar;

use Carbon\CarbonImmutable;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Holiday;
use Modules\Academic\Domain\Classroom\ScheduleEntry;

/**
 * Hari efektif sebuah tahun ajaran (PRD-002 §18 P4): hari di dalam tahun ajaran yang bukan libur
 * mingguan kalendernya dan bukan hari libur. Hanya membaca.
 *
 * @phpstan-type Rules array{weekly: list<int>, holidays: list<array{name: string, start: string, end: string}>}
 */
final class SchoolDays
{
    /** @var array<string, Rules> id tahun ajaran => aturan (cache per request) */
    private array $rules = [];

    /**
     * Alasan tanggal itu libur (mis. "Libur semester" atau "Ahad (libur mingguan)"), atau null bila
     * hari efektif. Tanggal di luar tahun ajaran dianggap bukan libur (dicek di tempat lain).
     */
    public function holidayOn(AcademicYear $year, string $date): ?string
    {
        $rules = $this->rulesOf($year);
        $reasons = [];

        foreach ($rules['holidays'] as $holiday) {
            if ($date >= $holiday['start'] && $date <= $holiday['end']) {
                $reasons[] = $holiday['name'];
            }
        }

        $day = CarbonImmutable::parse($date)->dayOfWeekIso;

        if (in_array($day, $rules['weekly'], true)) {
            $reasons[] = ScheduleEntry::dayName($day).' (libur mingguan)';
        }

        return $reasons === [] ? null : implode(', ', array_values(array_unique($reasons)));
    }

    /**
     * Hari efektif di rentang [start, end] yang dipotong ke dalam tahun ajaran, urut tanggal.
     *
     * @return list<string>
     */
    public function effectiveDates(AcademicYear $year, string $start, string $end): array
    {
        $from = max($start, $year->start_date->toDateString());
        $until = min($end, $year->end_date->toDateString());

        if ($from > $until) {
            return [];
        }

        $rules = $this->rulesOf($year);
        $off = [];

        foreach ($rules['holidays'] as $holiday) {
            $day = CarbonImmutable::parse(max($holiday['start'], $from));
            $last = min($holiday['end'], $until);

            while ($day->toDateString() <= $last) {
                $off[$day->toDateString()] = true;
                $day = $day->addDay();
            }
        }

        $dates = [];
        $day = CarbonImmutable::parse($from);

        while ($day->toDateString() <= $until) {
            $date = $day->toDateString();

            if (! isset($off[$date]) && ! in_array($day->dayOfWeekIso, $rules['weekly'], true)) {
                $dates[] = $date;
            }

            $day = $day->addDay();
        }

        return $dates;
    }

    /**
     * @return Rules
     */
    private function rulesOf(AcademicYear $year): array
    {
        if (isset($this->rules[$year->id])) {
            return $this->rules[$year->id];
        }

        $calendar = AcademicCalendar::query()->withoutGlobalScope('tenant')->whereKey($year->calendar_id)->first();
        $holidays = [];

        $rows = Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('academic_year_id', $year->id)
            ->orderBy('start_date')
            ->get();

        foreach ($rows as $holiday) {
            $holidays[] = [
                'name' => $holiday->name,
                'start' => $holiday->start_date->toDateString(),
                'end' => $holiday->end_date->toDateString(),
            ];
        }

        return $this->rules[$year->id] = [
            'weekly' => $calendar instanceof AcademicCalendar ? ManageHolidays::weeklyOffDaysOf($calendar) : AcademicCalendar::DEFAULT_WEEKLY_OFF_DAYS,
            'holidays' => $holidays,
        ];
    }
}
