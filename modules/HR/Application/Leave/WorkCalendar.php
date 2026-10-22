<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Modules\HR\Domain\Leave\Holiday;

/**
 * Kalender kerja HR (PRD-001 §13.2, OD-HR-07): hari kerja = hari yang bukan libur mingguan lembaga
 * (aturan `hr.weekly_off_days`) dan bukan hari libur yayasan (`hr_holidays`). Dipakai untuk
 * menghitung jumlah hari cuti.
 */
final class WorkCalendar
{
    public function __construct(
        private readonly WeeklyOffSetting $weeklyOff,
    ) {}

    /**
     * Tanggal hari kerja di [start, end] untuk lembaga ini, urut.
     *
     * @return list<string>
     */
    public function workingDays(string $tenantId, string $organizationId, string $start, string $end): array
    {
        if ($end < $start) {
            return [];
        }

        $weekly = $this->weeklyOff->daysFor($tenantId, $organizationId);
        $off = [];

        $holidays = Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->get();

        foreach ($holidays as $holiday) {
            $day = CarbonImmutable::parse(max($holiday->start_date->toDateString(), $start));
            $last = min($holiday->end_date->toDateString(), $end);

            while ($day->toDateString() <= $last) {
                $off[$day->toDateString()] = true;
                $day = $day->addDay();
            }
        }

        $dates = [];
        $day = CarbonImmutable::parse($start);

        while ($day->toDateString() <= $end) {
            if (! isset($off[$day->toDateString()]) && ! in_array($day->dayOfWeekIso, $weekly, true)) {
                $dates[] = $day->toDateString();
            }

            $day = $day->addDay();
        }

        return $dates;
    }
}
