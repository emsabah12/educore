<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\EnrolledStudents;
use stdClass;

/**
 * Isi kontrak EnrolledStudents (PRD-003 §7). Kelas = keanggotaan kelas yang masih terbuka pada
 * pendaftaran itu (tahun ajaran terbaru).
 */
final class AcademicEnrolledStudents implements EnrolledStudents
{
    public function search(string $tenantId, string $organizationId, string $query, int $limit): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $query));

        if ($text === '' || ! Str::isUuid($organizationId)) {
            return [];
        }

        $like = addcslashes($text, '%_\\');

        $rows = $this->base($tenantId)
            ->where('e.organization_id', $organizationId)
            ->whereNull('e.end_date')
            ->where(fn (Builder $where) => $where
                ->where('p.name', 'ilike', "%{$like}%")
                ->orWhere('s.nisn', 'like', "{$like}%")
                ->orWhere('e.student_number', 'ilike', "{$like}%"))
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->limit(max(1, min($limit, 50)))
            ->get($this->columns());

        $result = [];

        foreach ($rows as $row) {
            $result[] = $this->row($row);
        }

        return $result;
    }

    public function describe(string $tenantId, array $enrollmentIds): array
    {
        $ids = array_values(array_filter($enrollmentIds, fn (string $id): bool => Str::isUuid($id)));

        if ($ids === []) {
            return [];
        }

        $result = [];

        foreach ($this->base($tenantId)->whereIn('e.id', $ids)->get($this->columns()) as $row) {
            $result[(string) $row->enrollment_id] = $this->row($row);
        }

        return $result;
    }

    public function activeBetween(string $tenantId, string $organizationId, string $academicYearId, string $from, string $to): array
    {
        if (! Str::isUuid($organizationId) || ! Str::isUuid($academicYearId)) {
            return [];
        }

        $rows = $this->base($tenantId)
            ->where('e.organization_id', $organizationId)
            ->where('e.start_date', '<=', $to)
            ->where(fn (Builder $where) => $where->whereNull('e.end_date')->orWhere('e.end_date', '>=', $from))
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->select(['e.id as enrollment_id', 'p.name', 'e.start_date', 'e.end_date'])
            ->selectSub($this->latestClass($academicYearId, 'c.id'), 'class_id')
            ->selectSub($this->latestClass($academicYearId, 'c.name'), 'class_name')
            ->selectSub($this->latestClass($academicYearId, 'c.grade'), 'class_grade')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'enrollment_id' => (string) $row->enrollment_id,
                'name' => (string) $row->name,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => $row->end_date === null ? null : substr((string) $row->end_date, 0, 10),
                'class_id' => $row->class_id === null ? null : (string) $row->class_id,
                'class' => $row->class_name === null ? null : (string) $row->class_name,
                'grade' => $row->class_grade === null ? null : (int) $row->class_grade,
            ];
        }

        return $result;
    }

    public function classes(string $tenantId, string $organizationId, string $academicYearId): array
    {
        if (! Str::isUuid($organizationId) || ! Str::isUuid($academicYearId)) {
            return [];
        }

        $classes = [];

        foreach (DB::table('ac_classes')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->orderBy('grade')
            ->orderBy('name')
            ->get(['id', 'name', 'grade']) as $class) {
            $classes[] = ['id' => (string) $class->id, 'name' => (string) $class->name, 'grade' => (int) $class->grade];
        }

        return $classes;
    }

    /**
     * Subquery kolom kelas dari keanggotaan kelas terbaru pendaftaran `e` di tahun ajaran itu.
     */
    private function latestClass(string $academicYearId, string $column): Builder
    {
        return DB::table('ac_class_members as cm')
            ->join('ac_classes as c', 'c.id', '=', 'cm.class_id')
            ->whereColumn('cm.enrollment_id', 'e.id')
            ->where('cm.academic_year_id', $academicYearId)
            ->orderByDesc('cm.start_date')
            ->limit(1)
            ->select($column);
    }

    private function base(string $tenantId): Builder
    {
        return DB::table('ac_enrollments as e')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId);
    }

    /**
     * @return list<mixed>
     */
    private function columns(): array
    {
        return [
            'e.id as enrollment_id', 'e.student_id', 'e.organization_id', 'e.student_number', 'e.end_date', 's.nisn', 'p.name',
            DB::raw('(SELECT c.name FROM ac_class_members cm JOIN ac_classes c ON c.id = cm.class_id JOIN ac_academic_years y ON y.id = cm.academic_year_id WHERE cm.enrollment_id = e.id AND cm.end_date IS NULL ORDER BY y.start_date DESC LIMIT 1) AS class_name'),
        ];
    }

    /**
     * @return array{enrollment_id: string, student_id: string, organization_id: string, name: string, nisn: string|null, student_number: string|null, class: string|null, active: bool}
     */
    private function row(stdClass $row): array
    {
        return [
            'enrollment_id' => (string) $row->enrollment_id,
            'student_id' => (string) $row->student_id,
            'organization_id' => (string) $row->organization_id,
            'name' => (string) $row->name,
            'nisn' => $row->nisn === null ? null : (string) $row->nisn,
            'student_number' => $row->student_number === null ? null : (string) $row->student_number,
            'class' => $row->class_name === null ? null : (string) $row->class_name,
            'active' => $row->end_date === null,
        ];
    }
}
