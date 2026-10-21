<?php

namespace Modules\Academic\Application\Classroom;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\HR\Contracts\ActiveEmployees;
use Modules\HR\Contracts\EmployeeLookup;

/**
 * Menu "Kelas saya" (PRD-002 §16 AC-3d, OD-AC-06) [OWNER, 2026-10-21]:
 * - Untuk pengguna yang akunnya tertaut ke profil pegawai (kontrak HR EmployeeLookup) dan saat ini
 *   wali kelas atau guru pengampu di kelas tahun ajaran berjalan, selama ia masih pegawai aktif yang
 *   ditempatkan di lembaga kelas itu (kontrak HR ActiveEmployees).
 * - Semua guru melihat nama, L/P, NISN, nomor induk siswa kelasnya. Wali kelas juga melihat kontak
 *   utama orang tua/wali (nama + HP). Data pribadi lain tidak dikirim.
 * - Hanya dibaca; tidak membuka menu Siswa/Kelas biasa.
 * - Mencakup semua kelas yang ia pegang di yayasan ini, tidak dibatasi lembaga kerja aktif [ASUMSI]:
 *   guru SMP pagi & MDA sore melihat satu jadwal gabungan.
 *
 * @phpstan-type MyClass array{id: string, name: string, grade: int, organization: string, year: string, homeroom: bool, subjects: list<string>}
 * @phpstan-type MyStudent array{id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, contact: array{name: string, relation: string, phone: string|null}|null}
 * @phpstan-type MyLesson array{day: int, start: string, end: string, subject: string, class_id: string, class_name: string, organization: string}
 */
final class MyClasses
{
    public function __construct(
        private readonly EmployeeLookup $lookup,
        private readonly ActiveEmployees $employees,
    ) {}

    public function employeeId(string $tenantId, string $membershipId): ?string
    {
        return $this->lookup->employeeIdForMembership($tenantId, $membershipId);
    }

    /**
     * Kelas tahun ajaran berjalan yang dipegang pegawai ini (wali kelas dan/atau pengampu), urut
     * lembaga, tingkat, nama.
     *
     * @return list<MyClass>
     */
    public function classes(string $tenantId, string $employeeId): array
    {
        $activeOrganizations = $this->employees->describe($tenantId, [$employeeId])[$employeeId]['active_organization_ids'] ?? [];

        if ($activeOrganizations === []) {
            return [];
        }

        $rows = DB::table('ac_classes as c')
            ->join('ac_academic_years as y', 'y.id', '=', 'c.academic_year_id')
            ->join('organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('c.tenant_id', $tenantId)
            ->where('y.is_current', true)
            ->whereIn('c.organization_id', $activeOrganizations)
            ->where(function (Builder $query) use ($employeeId): void {
                $query->where('c.homeroom_employee_id', $employeeId)
                    ->orWhereExists(function (Builder $taught) use ($employeeId): void {
                        $taught->select(DB::raw(1))
                            ->from('ac_class_subjects as cs')
                            ->whereColumn('cs.class_id', 'c.id')
                            ->where('cs.teacher_employee_id', $employeeId);
                    });
            })
            ->orderBy('o.name')
            ->orderBy('c.grade')
            ->orderByRaw('lower(c.name)')
            ->get(['c.id', 'c.name', 'c.grade', 'c.homeroom_employee_id', 'o.name as organization', 'y.name as year']);

        $classIds = [];

        foreach ($rows as $row) {
            $classIds[] = (string) $row->id;
        }

        $subjects = $this->taughtSubjects($employeeId, $classIds);
        $classes = [];

        foreach ($rows as $row) {
            $classes[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'grade' => (int) $row->grade,
                'organization' => (string) $row->organization,
                'year' => (string) $row->year,
                'homeroom' => $row->homeroom_employee_id === $employeeId,
                'subjects' => $subjects[(string) $row->id] ?? [],
            ];
        }

        return $classes;
    }

    /**
     * @param  list<MyClass>  $classes
     * @return MyClass|null
     */
    public static function pick(array $classes, string $classId): ?array
    {
        if (! Str::isUuid($classId)) {
            return null;
        }

        foreach ($classes as $class) {
            if ($class['id'] === $classId) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Siswa yang masih di kelas, urut nama. Kontak utama hanya bila $withContacts (wali kelas).
     *
     * @return list<MyStudent>
     */
    public function students(string $classId, bool $withContacts): array
    {
        $query = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $classId)
            ->whereNull('cm.end_date')
            ->orderBy('p.name')
            ->orderBy('s.id');

        $columns = ['s.id', 'p.name', 'p.gender', 's.nisn', 'e.student_number'];

        if ($withContacts) {
            $query->leftJoin('ac_student_guardians as g', function (JoinClause $join): void {
                $join->on('g.student_id', '=', 's.id')->where('g.is_primary_contact', true);
            })
                ->leftJoin('memberships as gm', 'gm.id', '=', 'g.guardian_membership_id')
                ->leftJoin('persons as gp', 'gp.id', '=', 'gm.person_id');
            $columns = array_merge($columns, ['g.relation', 'gp.name as contact_name', 'gp.phone as contact_phone']);
        }

        $students = [];

        foreach ($query->get($columns) as $row) {
            $contact = null;

            if ($withContacts && ($row->contact_name ?? null) !== null) {
                $relation = GuardianRelation::tryFrom((string) $row->relation);
                $contact = [
                    'name' => (string) $row->contact_name,
                    'relation' => $relation?->label() ?? '',
                    'phone' => $row->contact_phone === null ? null : (string) $row->contact_phone,
                ];
            }

            $students[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'contact' => $contact,
            ];
        }

        return $students;
    }

    /**
     * Jadwal mengajar mingguan pegawai ini di kelas-kelas tersebut, urut hari & jam.
     *
     * @param  list<string>  $classIds
     * @return list<MyLesson>
     */
    public function schedule(string $employeeId, array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $rows = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->join('ac_classes as c', 'c.id', '=', 'e.class_id')
            ->join('organizations as o', 'o.id', '=', 'c.organization_id')
            ->whereIn('e.class_id', $classIds)
            ->where('cs.teacher_employee_id', $employeeId)
            ->orderBy('e.day_of_week')
            ->orderBy('e.start_time')
            ->orderBy('e.id')
            ->get(['e.day_of_week', 'e.start_time', 'e.end_time', 's.name', 'c.id as class_id', 'c.name as class_name', 'o.name as organization']);

        $lessons = [];

        foreach ($rows as $row) {
            $lessons[] = [
                'day' => (int) $row->day_of_week,
                'start' => substr((string) $row->start_time, 0, 5),
                'end' => substr((string) $row->end_time, 0, 5),
                'subject' => (string) $row->name,
                'class_id' => (string) $row->class_id,
                'class_name' => (string) $row->class_name,
                'organization' => (string) $row->organization,
            ];
        }

        return $lessons;
    }

    /**
     * Nama hari untuk halaman (1 Senin … 7 Ahad).
     *
     * @return list<array{value: int, label: string}>
     */
    public static function days(): array
    {
        $days = [];

        foreach (ScheduleEntry::DAYS as $value => $label) {
            $days[] = ['value' => $value, 'label' => $label];
        }

        return $days;
    }

    /**
     * @param  list<string>  $classIds
     * @return array<string, list<string>> id kelas => nama mapel yang diampu
     */
    private function taughtSubjects(string $employeeId, array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $rows = DB::table('ac_class_subjects as cs')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->whereIn('cs.class_id', $classIds)
            ->where('cs.teacher_employee_id', $employeeId)
            ->orderByRaw('lower(s.name)')
            ->get(['cs.class_id', 's.name']);

        $subjects = [];

        foreach ($rows as $row) {
            $subjects[(string) $row->class_id][] = (string) $row->name;
        }

        return $subjects;
    }
}
