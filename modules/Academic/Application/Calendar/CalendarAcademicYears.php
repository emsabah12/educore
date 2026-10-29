<?php

namespace Modules\Academic\Application\Calendar;

use Modules\Academic\Contracts\AcademicYears;
use Modules\Academic\Domain\Calendar\AcademicYear;

/**
 * Isi kontrak AcademicYears: tahun ajaran milik kalender yang dipakai lembaga (aturan berjenjang
 * kalender, PRD-002 §4.4), terbaru dulu.
 */
final class CalendarAcademicYears implements AcademicYears
{
    public function __construct(
        private readonly AcademicCalendarResolver $calendars,
    ) {}

    public function forOrganization(string $tenantId, string $organizationId): array
    {
        $calendar = $this->calendars->calendarFor($tenantId, $organizationId);

        if ($calendar === null) {
            return [];
        }

        $years = [];

        foreach (AcademicYear::query()->withoutGlobalScope('tenant')->where('calendar_id', $calendar->id)->orderByDesc('start_date')->get() as $year) {
            $years[] = [
                'id' => $year->id,
                'name' => $year->name,
                'start_date' => $year->start_date->toDateString(),
                'end_date' => $year->end_date->toDateString(),
                'is_current' => $year->is_current,
            ];
        }

        return $years;
    }
}
