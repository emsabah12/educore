<?php

namespace Modules\Academic\Application\Guardian;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Application\Calendar\ManageHolidays;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Portal "Anak saya" untuk orang tua/wali yang login (PRD-002 §21.2, AC-7a). Hanya baca.
 *
 * Akses TIDAK memakai izin/role: cukup tautan orang tua/wali di ac_student_guardians antara
 * membership yang sedang login dan siswa itu. Tautan dilepas → akses hilang saat itu juga.
 * Siswa yang bukan anaknya diperlakukan sama dengan tidak ada (404 di controller).
 *
 * Yang ditampilkan [ASUMSI disetujui 2026-10-28]: profil ringkas (tanpa NIK, HP, alamat), lembaga &
 * NIS, kelas tahun berjalan + wali kelas, jadwal pelajaran kelas, dan kalender akademik lembaganya.
 *
 * @phpstan-type ChildRow array{id: string, name: string, relation: string, organizations: list<string>, classes: list<string>}
 * @phpstan-type Lesson array{day: int, start: string, end: string, subject: string, teacher: string|null}
 * @phpstan-type ClassInfo array{id: string, name: string, organization: string, year: string, homeroom: string|null, schedule: list<Lesson>}
 * @phpstan-type CalendarInfo array{organization: string, year: array{name: string, start_date: string, end_date: string}|null, terms: list<array{name: string, start_date: string, end_date: string}>, weekly_off_days: list<string>, holidays: list<array{name: string, start_date: string, end_date: string}>}
 */
final class MyChildren
{
    public function __construct(
        private readonly AcademicCalendarResolver $calendars,
        private readonly ActiveEmployees $employees,
    ) {}

    public function isGuardian(string $tenantId, string $membershipId): bool
    {
        return DB::table('ac_student_guardians')
            ->where('tenant_id', $tenantId)
            ->where('guardian_membership_id', $membershipId)
            ->exists();
    }

    /**
     * Nama anak, atau null bila siswa ini bukan anak orang tua/wali yang login (→ 404).
     */
    public function name(string $tenantId, string $membershipId, string $studentId): ?string
    {
        if (! Str::isUuid($studentId)) {
            return null;
        }

        $name = $this->linked($tenantId, $membershipId)->where('s.id', $studentId)->value('p.name');

        return $name === null ? null : (string) $name;
    }

    /**
     * Anak-anak orang tua/wali ini, urut nama.
     *
     * @return list<ChildRow>
     */
    public function list(string $tenantId, string $membershipId): array
    {
        $rows = $this->linked($tenantId, $membershipId)
            ->orderBy('p.name')
            ->orderBy('s.id')
            ->get(['s.id', 'p.name', 'g.relation']);

        $children = [];

        foreach ($rows as $row) {
            $studentId = (string) $row->id;
            $classes = $this->currentClasses($studentId);

            $children[] = [
                'id' => $studentId,
                'name' => (string) $row->name,
                'relation' => $this->relationLabel((string) $row->relation),
                'organizations' => array_column($this->openEnrollments($studentId), 'organization'),
                'classes' => array_map(fn (array $class): string => "{$class['name']} — {$class['organization']}", $classes),
            ];
        }

        return $children;
    }

    /**
     * Data seorang anak, atau null bila siswa ini bukan anak orang tua/wali yang login.
     *
     * @return array{
     *     student: array{id: string, name: string, gender: string|null, nisn: string|null, birth_place: string|null, birth_date: string|null, relation: string},
     *     enrollments: list<array{organization: string, student_number: string|null, start_date: string}>,
     *     classes: list<ClassInfo>,
     *     calendars: list<CalendarInfo>
     * }|null
     */
    public function detail(string $tenantId, string $membershipId, string $studentId): ?array
    {
        if (! Str::isUuid($studentId)) {
            return null;
        }

        $row = $this->linked($tenantId, $membershipId)
            ->where('s.id', $studentId)
            ->first(['s.id', 's.nisn', 'p.name', 'p.gender', 'p.birth_place', 'p.birth_date', 'g.relation']);

        if ($row === null) {
            return null;
        }

        $gender = $row->gender === null ? null : Gender::tryFrom((string) $row->gender);
        $enrollments = $this->openEnrollments($studentId);
        $classes = $this->currentClasses($studentId);
        $this->attachSchedules($tenantId, $classes);

        return [
            'student' => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'gender' => $gender?->label(),
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'birth_place' => $row->birth_place === null ? null : (string) $row->birth_place,
                'birth_date' => $row->birth_date === null ? null : substr((string) $row->birth_date, 0, 10),
                'relation' => $this->relationLabel((string) $row->relation),
            ],
            'enrollments' => array_map(fn (array $enrollment): array => [
                'organization' => $enrollment['organization'],
                'student_number' => $enrollment['student_number'],
                'start_date' => $enrollment['start_date'],
            ], $enrollments),
            'classes' => $classes,
            'calendars' => array_map(
                fn (array $enrollment): array => $this->calendarOf($tenantId, $enrollment['organization_id'], $enrollment['organization']),
                $enrollments,
            ),
        ];
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Siswa yang tertaut ke orang tua/wali (membership) ini.
     */
    private function linked(string $tenantId, string $membershipId): Builder
    {
        return DB::table('ac_student_guardians as g')
            ->join('ac_students as s', 's.id', '=', 'g.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('g.tenant_id', $tenantId)
            ->where('g.guardian_membership_id', $membershipId);
    }

    /**
     * Pendaftaran yang masih berjalan, urut nama lembaga.
     *
     * @return list<array{organization_id: string, organization: string, student_number: string|null, start_date: string}>
     */
    private function openEnrollments(string $studentId): array
    {
        $rows = DB::table('ac_enrollments as e')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->where('e.student_id', $studentId)
            ->whereNull('e.end_date')
            ->orderBy('o.name')
            ->get(['e.organization_id', 'o.name', 'e.student_number', 'e.start_date']);

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'organization_id' => (string) $row->organization_id,
                'organization' => (string) $row->name,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'start_date' => substr((string) $row->start_date, 0, 10),
            ];
        }

        return $result;
    }

    /**
     * Kelas tahun ajaran berjalan (keanggotaan belum ditutup) beserta wali kelasnya.
     *
     * @return list<ClassInfo>
     */
    private function currentClasses(string $studentId): array
    {
        $today = BusinessClock::todayString();

        $rows = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_classes as c', 'c.id', '=', 'cm.class_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'cm.academic_year_id')
            ->join('organizations as o', 'o.id', '=', 'cm.organization_id')
            ->where('e.student_id', $studentId)
            ->whereNull('cm.end_date')
            ->where(fn ($query) => $query->where('y.is_current', true)
                ->orWhere(fn ($range) => $range->where('y.start_date', '<=', $today)->where('y.end_date', '>=', $today)))
            ->orderBy('o.name')
            ->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.homeroom_employee_id', 'c.tenant_id', 'o.name as organization', 'y.name as year']);

        $homeroomIds = [];

        foreach ($rows as $row) {
            if ($row->homeroom_employee_id !== null) {
                $homeroomIds[(string) $row->tenant_id][] = (string) $row->homeroom_employee_id;
            }
        }

        $names = [];

        foreach ($homeroomIds as $tenantId => $ids) {
            foreach ($this->employees->describe($tenantId, array_values(array_unique($ids))) as $id => $employee) {
                $names[$id] = $employee['name'];
            }
        }

        $classes = [];

        foreach ($rows as $row) {
            $classes[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'organization' => (string) $row->organization,
                'year' => (string) $row->year,
                'homeroom' => $row->homeroom_employee_id === null ? null : ($names[(string) $row->homeroom_employee_id] ?? null),
                'schedule' => [],
            ];
        }

        return $classes;
    }

    /**
     * Jadwal pelajaran tiap kelas (hari, jam, mapel, guru pengampu).
     *
     * @param  list<ClassInfo>  $classes
     */
    private function attachSchedules(string $tenantId, array &$classes): void
    {
        if ($classes === []) {
            return;
        }

        $rows = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('e.tenant_id', $tenantId)
            ->whereIn('e.class_id', array_column($classes, 'id'))
            ->orderBy('e.day_of_week')
            ->orderBy('e.start_time')
            ->orderBy('e.id')
            ->get(['e.class_id', 'e.day_of_week', 'e.start_time', 'e.end_time', 's.name', 'cs.teacher_employee_id']);

        $teacherIds = [];

        foreach ($rows as $row) {
            if ($row->teacher_employee_id !== null) {
                $teacherIds[] = (string) $row->teacher_employee_id;
            }
        }

        $teachers = $teacherIds === [] ? [] : $this->employees->describe($tenantId, array_values(array_unique($teacherIds)));
        $byClass = [];

        foreach ($rows as $row) {
            $teacherId = $row->teacher_employee_id === null ? null : (string) $row->teacher_employee_id;

            $byClass[(string) $row->class_id][] = [
                'day' => (int) $row->day_of_week,
                'start' => substr((string) $row->start_time, 0, 5),
                'end' => substr((string) $row->end_time, 0, 5),
                'subject' => (string) $row->name,
                'teacher' => $teacherId === null ? null : ($teachers[$teacherId]['name'] ?? null),
            ];
        }

        foreach ($classes as $index => $class) {
            $classes[$index]['schedule'] = $byClass[$class['id']] ?? [];
        }
    }

    /**
     * Kalender akademik yang dipakai lembaga: tahun ajaran berjalan, semester, libur mingguan, hari libur.
     *
     * @return CalendarInfo
     */
    private function calendarOf(string $tenantId, string $organizationId, string $organization): array
    {
        $calendar = $this->calendars->calendarFor($tenantId, $organizationId);
        $year = $calendar === null ? null : $this->calendars->currentYearOf($calendar);
        $weeklyOff = [];

        if ($calendar !== null) {
            foreach (ManageHolidays::weeklyOffDaysOf($calendar) as $day) {
                $weeklyOff[] = ScheduleEntry::DAYS[$day];
            }
        }

        $terms = [];
        $holidays = [];

        if ($year !== null) {
            foreach (DB::table('ac_terms')->where('academic_year_id', $year->id)->orderBy('sequence')->get(['name', 'start_date', 'end_date']) as $term) {
                $terms[] = ['name' => (string) $term->name, 'start_date' => substr((string) $term->start_date, 0, 10), 'end_date' => substr((string) $term->end_date, 0, 10)];
            }

            foreach (DB::table('ac_holidays')->where('academic_year_id', $year->id)->orderBy('start_date')->orderBy('name')->get(['name', 'start_date', 'end_date']) as $holiday) {
                $holidays[] = ['name' => (string) $holiday->name, 'start_date' => substr((string) $holiday->start_date, 0, 10), 'end_date' => substr((string) $holiday->end_date, 0, 10)];
            }
        }

        return [
            'organization' => $organization,
            'year' => $year === null ? null : [
                'name' => $year->name,
                'start_date' => $year->start_date->toDateString(),
                'end_date' => $year->end_date->toDateString(),
            ],
            'terms' => $terms,
            'weekly_off_days' => $weeklyOff,
            'holidays' => $holidays,
        ];
    }

    private function relationLabel(string $relation): string
    {
        return GuardianRelation::tryFrom($relation)?->label() ?? $relation;
    }
}
