<?php

namespace Modules\Academic\Application\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Exceptions\CalendarException;
use Modules\Academic\Domain\Calendar\Holiday;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Core\Application\Audit\AuditLogger;
use Throwable;

/**
 * Hari libur kalender akademik (PRD-002 §18 P4): hari libur mingguan per kalender dan daftar hari
 * libur per tahun ajaran. Hanya Admin Yayasan (dicek pemanggil, sama dengan kalender).
 *
 * Aturan:
 * - hari libur mingguan: 0–6 hari (minimal satu hari sekolah sepekan); bawaan Ahad;
 * - hari libur: nama 1–100 karakter, tanggal selesai ≥ tanggal mulai, di dalam rentang tahun
 *   ajarannya, paling banyak MAX_PER_YEAR per tahun ajaran; boleh tumpang tindih [ASUMSI].
 *
 * Perubahan diantrekan dengan mengunci baris kalender (sama dengan SaveAcademicYear), sehingga
 * tanggal tahun ajaran dan hari liburnya tidak berubah bersamaan.
 */
final class ManageHolidays
{
    public const NAME_MAX_LENGTH = 100;

    public const MAX_PER_YEAR = 200;

    public function __construct(
        private readonly ManageCalendars $calendars,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $days  nomor hari 1 (Senin) – 7 (Ahad)
     * @return bool true bila berubah
     *
     * @throws CalendarException
     */
    public function setWeeklyOffDays(string $tenantId, string $calendarId, array $days): bool
    {
        $days = array_values(array_unique($days));
        sort($days);

        foreach ($days as $day) {
            if (! isset(ScheduleEntry::DAYS[$day])) {
                throw new CalendarException('Pilih hari dari daftar.', 'weekly_off_days');
            }
        }

        if (count($days) > 6) {
            throw new CalendarException('Sisakan minimal satu hari sekolah dalam sepekan.', 'weekly_off_days');
        }

        return DB::transaction(function () use ($tenantId, $calendarId, $days): bool {
            $calendar = $this->calendars->lock($tenantId, $calendarId);
            $before = self::weeklyOffDaysOf($calendar);

            if ($before === $days) {
                return false;
            }

            $calendar->weekly_off_days = $days;
            $calendar->save();

            $this->audit->record(
                AcademicAuditAction::WEEKLY_OFF_DAYS_CHANGED,
                AcademicAuditAction::SUBJECT_CALENDAR,
                $calendar->id,
                ['kalender' => $calendar->name, 'sebelum' => self::dayNames($before), 'sesudah' => self::dayNames($days)],
                tenantId: $tenantId,
            );

            return true;
        });
    }

    /**
     * @throws CalendarException
     */
    public function add(string $tenantId, string $yearId, string $name, string $startDate, string $endDate): Holiday
    {
        $checked = $this->check($name, $startDate, $endDate);

        return DB::transaction(function () use ($tenantId, $yearId, $checked): Holiday {
            [$calendar, $year] = $this->lockYear($tenantId, $yearId);
            $this->assertInsideYear($year, $checked);

            $count = Holiday::query()->withoutGlobalScope('tenant')->where('academic_year_id', $year->id)->count();

            if ($count >= self::MAX_PER_YEAR) {
                throw new CalendarException('Paling banyak '.self::MAX_PER_YEAR.' hari libur per tahun ajaran.');
            }

            $holiday = new Holiday;
            $holiday->fill([
                'tenant_id' => $tenantId,
                'academic_year_id' => $year->id,
                'name' => $checked['name'],
                'start_date' => $checked['start'],
                'end_date' => $checked['end'],
            ]);
            $holiday->save();

            $this->audit->record(
                AcademicAuditAction::HOLIDAY_ADDED,
                AcademicAuditAction::SUBJECT_YEAR,
                $year->id,
                ['kalender' => $calendar->name, 'tahun_ajaran' => $year->name, ...$this->snapshot($holiday)],
                tenantId: $tenantId,
            );

            return $holiday;
        });
    }

    /**
     * @return bool true bila berubah
     *
     * @throws CalendarException
     */
    public function update(string $tenantId, string $holidayId, string $name, string $startDate, string $endDate): bool
    {
        $checked = $this->check($name, $startDate, $endDate);
        $yearId = $this->yearIdOf($tenantId, $holidayId);

        return DB::transaction(function () use ($tenantId, $holidayId, $yearId, $checked): bool {
            [$calendar, $year] = $this->lockYear($tenantId, $yearId);
            $holiday = $this->lockHoliday($year, $holidayId);
            $this->assertInsideYear($year, $checked);

            $before = $this->snapshot($holiday);

            $holiday->name = $checked['name'];
            $holiday->start_date = $checked['start'];
            $holiday->end_date = $checked['end'];

            $after = $this->snapshot($holiday);
            $changes = [];

            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = [$before[$field], $value];
                }
            }

            if ($changes === []) {
                return false;
            }

            $holiday->save();

            $this->audit->record(
                AcademicAuditAction::HOLIDAY_UPDATED,
                AcademicAuditAction::SUBJECT_YEAR,
                $year->id,
                ['kalender' => $calendar->name, 'tahun_ajaran' => $year->name, ...$changes],
                tenantId: $tenantId,
            );

            return true;
        });
    }

    /**
     * @return string nama hari libur yang dihapus
     *
     * @throws CalendarException
     */
    public function remove(string $tenantId, string $holidayId): string
    {
        $yearId = $this->yearIdOf($tenantId, $holidayId);

        return DB::transaction(function () use ($tenantId, $holidayId, $yearId): string {
            [$calendar, $year] = $this->lockYear($tenantId, $yearId);
            $holiday = $this->lockHoliday($year, $holidayId);
            $snapshot = $this->snapshot($holiday);

            $holiday->delete();

            $this->audit->record(
                AcademicAuditAction::HOLIDAY_REMOVED,
                AcademicAuditAction::SUBJECT_YEAR,
                $year->id,
                ['kalender' => $calendar->name, 'tahun_ajaran' => $year->name, ...$snapshot],
                tenantId: $tenantId,
            );

            return $holiday->name;
        });
    }

    /**
     * Hari libur mingguan kalender, terurut; data rusak/kosong dari database dibaca apa adanya.
     *
     * @return list<int>
     */
    public static function weeklyOffDaysOf(AcademicCalendar $calendar): array
    {
        $days = [];

        foreach ($calendar->weekly_off_days as $day) {
            if (is_int($day) && isset(ScheduleEntry::DAYS[$day])) {
                $days[] = $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * @param  list<int>  $days
     * @return list<string>
     */
    public static function dayNames(array $days): array
    {
        return array_map(fn (int $day): string => ScheduleEntry::dayName($day), $days);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array{name: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    private function check(string $name, string $startDate, string $endDate): array
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new CalendarException('Nama hari libur wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.', 'name');
        }

        $start = $this->date($startDate, 'start_date');
        $end = $this->date($endDate, 'end_date');

        if ($end->lessThan($start)) {
            throw new CalendarException('Tanggal selesai tidak boleh sebelum tanggal mulai.', 'end_date');
        }

        return ['name' => $name, 'start' => $start, 'end' => $end];
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== trim($value)) {
            throw new CalendarException('Tanggal tidak valid.', $field);
        }

        return $date;
    }

    /**
     * @param  array{name: string, start: CarbonImmutable, end: CarbonImmutable}  $checked
     */
    private function assertInsideYear(AcademicYear $year, array $checked): void
    {
        if ($checked['start']->lessThan($year->start_date) || $checked['end']->greaterThan($year->end_date)) {
            throw new CalendarException(
                "Hari libur harus di dalam tahun ajaran {$year->name} ({$year->start_date->toDateString()} s.d. {$year->end_date->toDateString()}).",
                'start_date',
            );
        }
    }

    /**
     * @return array{0: AcademicCalendar, 1: AcademicYear}
     */
    private function lockYear(string $tenantId, string $yearId): array
    {
        $calendarId = Str::isUuid($yearId)
            ? AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($yearId)->value('calendar_id')
            : null;

        if (! is_string($calendarId)) {
            throw CalendarException::yearNotFound();
        }

        $calendar = $this->calendars->lock($tenantId, $calendarId);
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('calendar_id', $calendar->id)->whereKey($yearId)->first();

        if (! $year instanceof AcademicYear) {
            throw CalendarException::yearNotFound();
        }

        return [$calendar, $year];
    }

    private function lockHoliday(AcademicYear $year, string $holidayId): Holiday
    {
        $holiday = Holiday::query()->withoutGlobalScope('tenant')->where('academic_year_id', $year->id)->whereKey($holidayId)->lockForUpdate()->first();

        if (! $holiday instanceof Holiday) {
            throw self::holidayNotFound();
        }

        return $holiday;
    }

    private function yearIdOf(string $tenantId, string $holidayId): string
    {
        $yearId = Str::isUuid($holidayId)
            ? Holiday::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($holidayId)->value('academic_year_id')
            : null;

        if (! is_string($yearId)) {
            throw self::holidayNotFound();
        }

        return $yearId;
    }

    private static function holidayNotFound(): CalendarException
    {
        return new CalendarException('Hari libur ini sudah tidak ada. Muat ulang halaman.');
    }

    /**
     * @return array{nama: string, mulai: string, selesai: string}
     */
    private function snapshot(Holiday $holiday): array
    {
        return [
            'nama' => $holiday->name,
            'mulai' => $holiday->start_date->toDateString(),
            'selesai' => $holiday->end_date->toDateString(),
        ];
    }
}
