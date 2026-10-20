<?php

namespace Modules\Academic\Application\Classroom;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Daftar kelas per lembaga & tahun ajaran (PRD-002 §6, AC-1d-a): nama rombel, tingkat, wali kelas,
 * jumlah siswa (L/P). Pemanggil WAJIB sudah memastikan lembaganya terlihat oleh pengguna.
 *
 * @phpstan-type YearOption array{value: string, label: string, current: bool, past: bool}
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
}
