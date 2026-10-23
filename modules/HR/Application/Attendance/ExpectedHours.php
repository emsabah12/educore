<?php

namespace Modules\HR\Application\Attendance;

use Carbon\CarbonImmutable;
use Modules\HR\Application\Leave\WorkCalendar;
use Modules\HR\Contracts\TeachingSchedule;

/**
 * Jam wajib hadir pegawai per tanggal (PRD-001 §14.1, OD-HR-09).
 *
 * - Guru (punya jadwal mengajar di tahun ajaran yang memuat tanggal itu): pelajaran pertama s.d.
 *   terakhir hari itu; tanpa pelajaran / libur sekolah → tidak wajib.
 * - Staf: jam kerja `hr.work_hours` lembaga penempatan utama pada hari kerja kalender kerja HR.
 * - Toleransi terlambat selalu dari `hr.work_hours` lembaga penempatan utama.
 *
 * @phpstan-type Expected array{required: bool, start: string|null, end: string|null, tolerance: int, basis: string, label: string}
 */
final class ExpectedHours
{
    public const BASIS_SCHEDULE = 'jadwal';

    public const BASIS_WORK_HOURS = 'jam_kerja';

    public const BASIS_NO_LESSON = 'tanpa_pelajaran';

    public const BASIS_DAY_OFF = 'libur';

    public function __construct(
        private readonly TeachingSchedule $teaching,
        private readonly WorkHoursSetting $hours,
        private readonly WorkCalendar $calendar,
    ) {}

    /**
     * @return array<string, Expected> tanggal → jam wajib
     */
    public function forEmployee(string $tenantId, string $employeeId, string $organizationId, string $start, string $end): array
    {
        return $this->forEmployees($tenantId, [$employeeId => $organizationId], $start, $end)[$employeeId] ?? [];
    }

    /**
     * @param  array<string, string>  $organizations  pegawai → lembaga penempatan utama
     * @return array<string, array<string, Expected>> pegawai → tanggal → jam wajib
     */
    public function forEmployees(string $tenantId, array $organizations, string $start, string $end): array
    {
        if ($organizations === [] || $end < $start) {
            return [];
        }

        $teaching = $this->teaching->windows($tenantId, array_map('strval', array_keys($organizations)), $start, $end);
        $hoursByOrganization = [];
        $workingByOrganization = [];
        $result = [];

        foreach ($organizations as $employeeId => $organizationId) {
            $employeeId = (string) $employeeId;
            $hours = $hoursByOrganization[$organizationId] ??= $this->hours->for($tenantId, $organizationId);
            $working = $workingByOrganization[$organizationId] ??= array_flip($this->calendar->workingDays($tenantId, $organizationId, $start, $end));
            $lessons = $teaching[$employeeId] ?? [];
            $day = CarbonImmutable::parse($start);

            while ($day->toDateString() <= $end) {
                $date = $day->toDateString();

                if (array_key_exists($date, $lessons)) {
                    $window = $lessons[$date];

                    $result[$employeeId][$date] = $window === null
                        ? ['required' => false, 'start' => null, 'end' => null, 'tolerance' => $hours['tolerance'], 'basis' => self::BASIS_NO_LESSON, 'label' => 'Tidak ada jadwal mengajar']
                        : ['required' => true, 'start' => $window['start'], 'end' => $window['end'], 'tolerance' => $hours['tolerance'], 'basis' => self::BASIS_SCHEDULE, 'label' => self::range($window['start'], $window['end']).' (jadwal mengajar)'];
                } elseif (isset($working[$date])) {
                    $result[$employeeId][$date] = ['required' => true, 'start' => $hours['start'], 'end' => $hours['end'], 'tolerance' => $hours['tolerance'], 'basis' => self::BASIS_WORK_HOURS, 'label' => self::range($hours['start'], $hours['end']).' (jam kerja)'];
                } else {
                    $result[$employeeId][$date] = ['required' => false, 'start' => null, 'end' => null, 'tolerance' => $hours['tolerance'], 'basis' => self::BASIS_DAY_OFF, 'label' => 'Libur'];
                }

                $day = $day->addDay();
            }
        }

        return $result;
    }

    /**
     * Menit terlambat (dihitung dari jam wajib) bila lewat toleransi; selain itu 0.
     */
    public static function lateMinutes(?string $expectedStart, int $tolerance, string $checkIn): int
    {
        if ($expectedStart === null) {
            return 0;
        }

        $late = self::minutes($checkIn) - self::minutes($expectedStart);

        return $late > $tolerance ? $late : 0;
    }

    /**
     * Menit pulang lebih cepat dari jam wajib selesai; selain itu 0.
     */
    public static function earlyMinutes(?string $expectedEnd, string $checkOut): int
    {
        if ($expectedEnd === null) {
            return 0;
        }

        return max(0, self::minutes($expectedEnd) - self::minutes($checkOut));
    }

    public static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private static function range(string $start, string $end): string
    {
        return str_replace(':', '.', $start).'–'.str_replace(':', '.', $end);
    }
}
