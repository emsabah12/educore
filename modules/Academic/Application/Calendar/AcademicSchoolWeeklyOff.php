<?php

namespace Modules\Academic\Application\Calendar;

use Modules\HR\Contracts\SchoolWeeklyOff;

/**
 * Libur mingguan kalender akademik tiap lembaga untuk perbandingan di halaman HR (PRD-001 §13.2,
 * OD-HR-07). Hanya membaca.
 */
final class AcademicSchoolWeeklyOff implements SchoolWeeklyOff
{
    public function __construct(
        private readonly AcademicCalendarResolver $calendars,
    ) {}

    public function forOrganizations(string $tenantId, array $organizationIds): array
    {
        $result = [];

        foreach ($organizationIds as $organizationId) {
            $calendar = $this->calendars->calendarFor($tenantId, $organizationId);

            if ($calendar !== null) {
                $result[$organizationId] = ManageHolidays::weeklyOffDaysOf($calendar);
            }
        }

        return $result;
    }
}
