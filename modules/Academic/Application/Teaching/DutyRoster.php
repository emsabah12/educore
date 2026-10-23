<?php

namespace Modules\Academic\Application\Teaching;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Teaching\Exceptions\TeachingException;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Jadwal piket per lembaga × tahun ajaran × hari (PRD-002 §20.2, OD-AC-13).
 *
 * - Tahun ajaran = tahun di kalender akademik lembaga itu yang belum lewat.
 * - Guru piket = pegawai aktif lembaga itu (kontrak HR ActiveEmployees), maks. 20 per hari [ASUMSI].
 * - Menyimpan satu hari mengganti seluruh daftar hari itu.
 *
 * Hak akses (`academic.teaching.manage` di lembaga itu) dicek pemanggil.
 *
 * @phpstan-type YearOption array{value: string, label: string}
 * @phpstan-type DayRow array{day: int, name: string, employees: list<array{id: string, name: string}>}
 */
final class DutyRoster
{
    public const MAX_PER_DAY = 20;

    public function __construct(
        private readonly AcademicCalendarResolver $calendars,
        private readonly ActiveEmployees $employees,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tahun ajaran yang bisa diatur jadwal piketnya (belum lewat), urut mulai.
     *
     * @return list<YearOption>
     */
    public function years(string $tenantId, string $organizationId): array
    {
        $calendar = $this->calendars->calendarFor($tenantId, $organizationId);

        if ($calendar === null) {
            return [];
        }

        $options = [];

        $years = AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('calendar_id', $calendar->id)
            ->where('end_date', '>=', BusinessClock::todayString())
            ->orderBy('start_date')
            ->get();

        foreach ($years as $year) {
            $options[] = ['value' => $year->id, 'label' => $year->name.($year->is_current ? ' (berjalan)' : '')];
        }

        return $options;
    }

    /**
     * @return list<DayRow>
     */
    public function days(string $tenantId, string $organizationId, string $yearId): array
    {
        $rows = DB::table('ac_duty_rosters')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $yearId)
            ->get(['day_of_week', 'employee_id']);

        $names = $this->employees->describe($tenantId, array_values(array_unique(array_map(fn ($row): string => (string) $row->employee_id, $rows->all()))));
        $byDay = [];

        foreach ($rows as $row) {
            $id = (string) $row->employee_id;
            $byDay[(int) $row->day_of_week][] = ['id' => $id, 'name' => $names[$id]['name'] ?? '—'];
        }

        $days = [];

        foreach (ScheduleEntry::DAYS as $day => $name) {
            $list = $byDay[$day] ?? [];
            usort($list, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $days[] = ['day' => $day, 'name' => $name, 'employees' => $list];
        }

        return $days;
    }

    /**
     * Ganti daftar guru piket satu hari.
     *
     * @param  list<string>  $employeeIds
     *
     * @throws TeachingException
     */
    public function set(string $tenantId, string $organizationId, string $yearId, int $day, array $employeeIds, string $membershipId): int
    {
        if (! isset(ScheduleEntry::DAYS[$day])) {
            throw TeachingException::make('day', 'Pilih hari dari daftar.');
        }

        $employeeIds = array_values(array_unique($employeeIds));

        if (count($employeeIds) > self::MAX_PER_DAY) {
            throw TeachingException::make('employee_ids', 'Paling banyak '.self::MAX_PER_DAY.' guru piket per hari.');
        }

        $allowedYears = array_column($this->years($tenantId, $organizationId), 'value');

        if (! Str::isUuid($yearId) || ! in_array($yearId, $allowedYears, true)) {
            throw TeachingException::make('academic_year_id', 'Pilih tahun ajaran lembaga ini yang belum lewat.');
        }

        foreach ($employeeIds as $employeeId) {
            if (! $this->employees->isActiveIn($tenantId, $employeeId, $organizationId)) {
                throw TeachingException::make('employee_ids', 'Guru piket harus pegawai aktif yang ditempatkan di lembaga ini.');
            }
        }

        return DB::transaction(function () use ($tenantId, $organizationId, $yearId, $day, $employeeIds): int {
            DB::table('ac_duty_rosters')
                ->where('organization_id', $organizationId)
                ->where('academic_year_id', $yearId)
                ->where('day_of_week', $day)
                ->delete();

            $now = CarbonImmutable::now();

            foreach ($employeeIds as $employeeId) {
                DB::table('ac_duty_rosters')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'organization_id' => $organizationId,
                    'academic_year_id' => $yearId,
                    'day_of_week' => $day,
                    'employee_id' => $employeeId,
                    'created_at' => $now,
                ]);
            }

            $this->audit->record(
                AcademicAuditAction::DUTY_ROSTER_UPDATED,
                AcademicAuditAction::SUBJECT_YEAR,
                $yearId,
                ['hari' => ScheduleEntry::dayName($day), 'jumlah_guru' => count($employeeIds)],
                organizationId: $organizationId,
                tenantId: $tenantId,
            );

            return count($employeeIds);
        });
    }

    /**
     * Pegawai ini piket di lembaga itu pada tanggal itu (hari & tahun ajaran yang memuatnya).
     */
    public function isOnDuty(string $tenantId, string $organizationId, string $employeeId, string $date): bool
    {
        return in_array($organizationId, $this->dutyOrganizations($tenantId, $employeeId, $date), true);
    }

    /**
     * Lembaga tempat pegawai ini piket pada tanggal itu.
     *
     * @return list<string>
     */
    public function dutyOrganizations(string $tenantId, string $employeeId, string $date): array
    {
        $ids = DB::table('ac_duty_rosters as r')
            ->join('ac_academic_years as y', 'y.id', '=', 'r.academic_year_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.employee_id', $employeeId)
            ->where('r.day_of_week', CarbonImmutable::parse($date)->dayOfWeekIso)
            ->where('y.start_date', '<=', $date)
            ->where('y.end_date', '>=', $date)
            ->distinct()
            ->pluck('r.organization_id')
            ->all();

        return array_values(array_map('strval', $ids));
    }

    /**
     * Nama pegawai (kontrak HR), untuk menampilkan guru piket.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, string>
     */
    public function namesOf(string $tenantId, array $employeeIds): array
    {
        $names = [];

        foreach ($this->employees->describe($tenantId, $employeeIds) as $id => $employee) {
            $names[(string) $id] = $employee['name'];
        }

        return $names;
    }

    /**
     * Guru piket lembaga itu pada tanggal itu (ID pegawai).
     *
     * @return list<string>
     */
    public function onDuty(string $tenantId, string $organizationId, string $date): array
    {
        $ids = DB::table('ac_duty_rosters as r')
            ->join('ac_academic_years as y', 'y.id', '=', 'r.academic_year_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.organization_id', $organizationId)
            ->where('r.day_of_week', CarbonImmutable::parse($date)->dayOfWeekIso)
            ->where('y.start_date', '<=', $date)
            ->where('y.end_date', '>=', $date)
            ->pluck('r.employee_id')
            ->all();

        return array_values(array_map('strval', $ids));
    }
}
