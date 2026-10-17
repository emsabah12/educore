<?php

namespace Modules\Academic\Application\Calendar;

use Illuminate\Support\Str;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Application\Settings\ResolvedSetting;
use Modules\Core\Application\Settings\ScopedSettingResolver;

/**
 * Menjawab "lembaga X memakai kalender apa, dan tahun ajaran berjalannya apa?" (PRD-002 §4.4).
 *
 * Urutan: aturan `academic.calendar` yang berlaku di lembaga itu (terdekat menang, kecuali dikunci);
 * bila kosong atau kalendernya tidak ditemukan → Kalender Yayasan. Dipakai kelas (AC-1d) dan
 * halaman Kalender akademik.
 */
final class AcademicCalendarResolver
{
    public function __construct(
        private readonly ScopedSettingResolver $settings,
        private readonly ManageCalendars $calendars,
    ) {}

    public function calendarFor(string $tenantId, string $organizationId): ?AcademicCalendar
    {
        return $this->usageFor($tenantId, $organizationId)['calendar'];
    }

    public function currentYearFor(string $tenantId, string $organizationId): ?AcademicYear
    {
        $calendar = $this->calendarFor($tenantId, $organizationId);

        return $calendar === null ? null : $this->currentYearOf($calendar);
    }

    /**
     * Kalender yang berlaku beserta asal-usul aturannya (default / terdekat / dikunci).
     *
     * @return array{calendar: AcademicCalendar|null, setting: ResolvedSetting}
     */
    public function usageFor(string $tenantId, string $organizationId): array
    {
        $setting = $this->settings->resolve($tenantId, CalendarSetting::KEY, $organizationId);
        $calendar = null;

        if (is_string($setting->value) && Str::isUuid($setting->value)) {
            $calendar = AcademicCalendar::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereKey($setting->value)
                ->first();
        }

        return [
            'calendar' => $calendar ?? $this->calendars->defaultOf($tenantId),
            'setting' => $setting,
        ];
    }

    public function currentYearOf(AcademicCalendar $calendar): ?AcademicYear
    {
        return AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('calendar_id', $calendar->id)
            ->where('is_current', true)
            ->first();
    }
}
