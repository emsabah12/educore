<?php

namespace Modules\Academic\Application\Classroom;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Daftar kelas per lembaga & tahun ajaran (PRD-002 §6, AC-1d-a): nama rombel, tingkat, wali kelas,
 * jumlah siswa (L/P). Pemanggil WAJIB sudah memastikan lembaganya terlihat oleh pengguna.
 *
 * @phpstan-type YearOption array{value: string, label: string, current: bool, past: bool}
 * @phpstan-type MemberRow array{id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, start_date: string, end_date: string|null}
 * @phpstan-type SubjectRow array{id: string, subject_id: string, name: string, code: string|null, teacher: array{id: string, name: string, active: bool}|null}
 * @phpstan-type ScheduleRow array{id: string, class_subject_id: string, day: int, start: string, end: string, subject: string, teacher: string|null}
 * @phpstan-type ClassRow array{id: string, name: string, grade: int, is_active: bool, homeroom: array{id: string, name: string, active: bool}|null, members: array{total: int, male: int, female: int}, has_history: bool}
 */
final class ClassDirectory
{
    public function __construct(
        private readonly AcademicCalendarResolver $calendars,
        private readonly ActiveEmployees $employees,
    ) {}

    /**
     * Tahun ajaran dari kalender yang berlaku untuk lembaga ini, terbaru dulu.
     *
     * @return list<YearOption>
     */
    public function yearOptions(string $tenantId, string $organizationId): array
    {
        $calendar = $this->calendars->calendarFor($tenantId, $organizationId);

        if ($calendar === null) {
            return [];
        }

        $years = AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('calendar_id', $calendar->id)
            ->orderByDesc('start_date')
            ->get();

        $options = [];

        foreach ($years as $year) {
            $options[] = [
                'value' => $year->id,
                'label' => $year->name.($year->is_current ? ' (berjalan)' : ''),
                'current' => $year->is_current,
                'past' => ManageClasses::isPastYear($year),
            ];
        }

        return $options;
    }

    /**
     * @return list<ClassRow>
     */
    public function list(string $tenantId, string $organizationId, string $academicYearId): array
    {
        // Anggota yang masih di kelas (end_date kosong), dihitung per jenis kelamin dari Person.
        $rows = DB::table('ac_classes as c')
            ->where('c.tenant_id', $tenantId)
            ->where('c.organization_id', $organizationId)
            ->where('c.academic_year_id', $academicYearId)
            ->select(['c.id', 'c.name', 'c.grade', 'c.is_active', 'c.homeroom_employee_id'])
            ->selectRaw('(SELECT count(*) FROM ac_class_members cm WHERE cm.class_id = c.id AND cm.end_date IS NULL) AS total_members')
            ->selectRaw("(SELECT count(*) FROM ac_class_members cm JOIN ac_enrollments e ON e.id = cm.enrollment_id JOIN ac_students s ON s.id = e.student_id JOIN memberships m ON m.id = s.membership_id JOIN persons p ON p.id = m.person_id WHERE cm.class_id = c.id AND cm.end_date IS NULL AND p.gender = 'L') AS male_members")
            ->selectRaw("(SELECT count(*) FROM ac_class_members cm JOIN ac_enrollments e ON e.id = cm.enrollment_id JOIN ac_students s ON s.id = e.student_id JOIN memberships m ON m.id = s.membership_id JOIN persons p ON p.id = m.person_id WHERE cm.class_id = c.id AND cm.end_date IS NULL AND p.gender = 'P') AS female_members")
            ->selectRaw('EXISTS (SELECT 1 FROM ac_class_members cm WHERE cm.class_id = c.id) AS has_history')
            ->orderBy('c.grade')
            ->orderByRaw('lower(c.name)')
            ->get();

        $homeroomIds = [];

        foreach ($rows as $row) {
            if ($row->homeroom_employee_id !== null) {
                $homeroomIds[] = (string) $row->homeroom_employee_id;
            }
        }

        $homerooms = $this->employees->describe($tenantId, array_values(array_unique($homeroomIds)));
        $classes = [];

        foreach ($rows as $row) {
            $homeroom = null;
            $homeroomId = $row->homeroom_employee_id === null ? null : (string) $row->homeroom_employee_id;

            if ($homeroomId !== null && isset($homerooms[$homeroomId])) {
                $homeroom = [
                    'id' => $homeroomId,
                    'name' => $homerooms[$homeroomId]['name'],
                    // Wali kelas yang berhenti/dimutasi tetap tercatat, tetapi ditandai (§7).
                    'active' => in_array($organizationId, $homerooms[$homeroomId]['active_organization_ids'], true),
                ];
            }

            $classes[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'grade' => (int) $row->grade,
                'is_active' => (bool) $row->is_active,
                'homeroom' => $homeroom,
                'members' => [
                    'total' => (int) $row->total_members,
                    'male' => (int) $row->male_members,
                    'female' => (int) $row->female_members,
                ],
                'has_history' => (bool) $row->has_history,
            ];
        }

        return $classes;
    }

    /**
     * Isi halaman detail kelas (AC-1d-b): data kelas, anggota yang masih di kelas, yang pernah di
     * kelas ini, dan kelas tujuan pindah (kelas aktif lain di lembaga & tahun ajaran yang sama).
     *
     * @return array{
     *     class: array{id: string, name: string, grade: int, is_active: bool, organization: string, homeroom: array{id: string, name: string, active: bool}|null},
     *     year: array{id: string, name: string, current: bool, past: bool, starts_in_future: bool, start_date: string, end_date: string},
     *     members: list<MemberRow>,
     *     former: list<MemberRow>,
     *     moveTargets: list<array{value: string, label: string}>,
     *     subjects: list<SubjectRow>,
     *     schedule: list<ScheduleRow>,
     * }
     */
    public function detail(SchoolClass $class): array
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $organization = (string) DB::table('organizations')->where('id', $class->organization_id)->value('name');
        $homeroom = null;

        if ($class->homeroom_employee_id !== null) {
            $described = $this->employees->describe($class->tenant_id, [$class->homeroom_employee_id])[$class->homeroom_employee_id] ?? null;

            if ($described !== null) {
                $homeroom = [
                    'id' => $class->homeroom_employee_id,
                    'name' => $described['name'],
                    'active' => in_array($class->organization_id, $described['active_organization_ids'], true),
                ];
            }
        }

        $rows = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $class->id)
            ->orderBy('p.name')
            ->orderBy('cm.start_date')
            ->get(['cm.id', 'cm.start_date', 'cm.end_date', 'e.student_id', 'e.student_number', 's.nisn', 'p.name', 'p.gender']);

        $members = [];
        $former = [];

        foreach ($rows as $row) {
            $item = [
                'id' => (string) $row->id,
                'student_id' => (string) $row->student_id,
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => $row->end_date === null ? null : substr((string) $row->end_date, 0, 10),
            ];

            if ($item['end_date'] === null) {
                $members[] = $item;
            } else {
                $former[] = $item;
            }
        }

        return [
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'grade' => $class->grade,
                'is_active' => $class->is_active,
                'organization' => $organization,
                'homeroom' => $homeroom,
            ],
            'year' => [
                'id' => $year->id,
                'name' => $year->name,
                'current' => $year->is_current,
                'past' => ManageClasses::isPastYear($year),
                'starts_in_future' => ManageClassMembers::startsInFuture($year),
                'start_date' => $year->start_date->toDateString(),
                'end_date' => $year->end_date->toDateString(),
            ],
            'members' => $members,
            'former' => $former,
            'moveTargets' => $this->moveTargets($class->organization_id, $class->academic_year_id, $class->id),
            'subjects' => $this->subjects($class),
            'schedule' => $this->schedule($class),
        ];
    }

    /**
     * Jadwal mingguan kelas (AC-3c), urut hari lalu jam mulai. Jam "HH:MM".
     *
     * @return list<ScheduleRow>
     */
    public function schedule(SchoolClass $class): array
    {
        $rows = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('e.class_id', $class->id)
            ->orderBy('e.day_of_week')
            ->orderBy('e.start_time')
            ->orderBy('e.id')
            ->get(['e.id', 'e.class_subject_id', 'e.day_of_week', 'e.start_time', 'e.end_time', 's.name', 'cs.teacher_employee_id']);

        $teacherIds = [];

        foreach ($rows as $row) {
            if ($row->teacher_employee_id !== null) {
                $teacherIds[] = (string) $row->teacher_employee_id;
            }
        }

        $teachers = $this->employees->describe($class->tenant_id, array_values(array_unique($teacherIds)));
        $entries = [];

        foreach ($rows as $row) {
            $teacherId = $row->teacher_employee_id === null ? null : (string) $row->teacher_employee_id;

            $entries[] = [
                'id' => (string) $row->id,
                'class_subject_id' => (string) $row->class_subject_id,
                'day' => (int) $row->day_of_week,
                'start' => substr((string) $row->start_time, 0, 5),
                'end' => substr((string) $row->end_time, 0, 5),
                'subject' => (string) $row->name,
                'teacher' => $teacherId !== null && isset($teachers[$teacherId]) ? $teachers[$teacherId]['name'] : null,
            ];
        }

        return $entries;
    }

    /**
     * Mata pelajaran kelas & guru pengampunya (AC-3b), urut nama mapel. Pengampu yang sudah tidak
     * aktif di lembaga kelas tetap tampil, ditandai.
     *
     * @return list<SubjectRow>
     */
    public function subjects(SchoolClass $class): array
    {
        $rows = DB::table('ac_class_subjects as cs')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.class_id', $class->id)
            ->orderByRaw('lower(s.name)')
            ->orderBy('cs.id')
            ->get(['cs.id', 'cs.subject_id', 'cs.teacher_employee_id', 's.name', 's.code']);

        $teacherIds = [];

        foreach ($rows as $row) {
            if ($row->teacher_employee_id !== null) {
                $teacherIds[] = (string) $row->teacher_employee_id;
            }
        }

        $teachers = $this->employees->describe($class->tenant_id, array_values(array_unique($teacherIds)));
        $subjects = [];

        foreach ($rows as $row) {
            $teacherId = $row->teacher_employee_id === null ? null : (string) $row->teacher_employee_id;
            $teacher = null;

            if ($teacherId !== null && isset($teachers[$teacherId])) {
                $teacher = [
                    'id' => $teacherId,
                    'name' => $teachers[$teacherId]['name'],
                    'active' => in_array($class->organization_id, $teachers[$teacherId]['active_organization_ids'], true),
                ];
            }

            $subjects[] = [
                'id' => (string) $row->id,
                'subject_id' => (string) $row->subject_id,
                'name' => (string) $row->name,
                'code' => $row->code === null ? null : (string) $row->code,
                'teacher' => $teacher,
            ];
        }

        return $subjects;
    }

    /**
     * Kelas tujuan pindah: kelas aktif lain di lembaga & tahun ajaran yang sama.
     *
     * @return list<array{value: string, label: string}>
     */
    public function moveTargets(string $organizationId, string $academicYearId, string $exceptClassId): array
    {
        $targets = [];

        $classes = SchoolClass::query()
            ->withoutGlobalScope('tenant')
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->whereKeyNot($exceptClassId)
            ->orderBy('grade')
            ->orderBy('name')
            ->get();

        foreach ($classes as $target) {
            $targets[] = ['value' => $target->id, 'label' => $target->name];
        }

        return $targets;
    }
}
