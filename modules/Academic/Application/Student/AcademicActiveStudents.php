<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\ActiveStudents;

/**
 * Isi kontrak ActiveStudents (PRD-008 §16.2). Kelas = keanggotaan kelas yang masih terbuka pada pendaftaran itu
 * (tahun ajaran terbaru), sama dengan AcademicEnrolledStudents.
 */
final class AcademicActiveStudents implements ActiveStudents
{
    public const SEARCH_MAX = 20;

    public function search(string $tenantId, array $organizationIds, string $query, int $limit): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $query));
        $organizations = self::uuids($organizationIds);

        if ($text === '' || $organizations === []) {
            return [];
        }

        $like = addcslashes($text, '%_\\');

        $found = $this->openEnrollments($tenantId, $organizations)
            ->where(fn (Builder $where) => $where
                ->where('p.name', 'ilike', "%{$like}%")
                ->orWhere('s.nisn', 'like', "{$like}%")
                ->orWhere('e.student_number', 'ilike', "{$like}%"))
            ->groupBy('s.membership_id', 'p.name')
            ->orderBy('p.name')
            ->orderBy('s.membership_id')
            ->limit(max(1, min($limit, self::SEARCH_MAX)))
            ->pluck('s.membership_id');

        $memberships = [];

        foreach ($found as $id) {
            $memberships[] = (string) $id;
        }

        $described = $this->rows($tenantId, $memberships, $organizations);
        $result = [];

        foreach ($memberships as $membershipId) {
            if (isset($described[$membershipId])) {
                $result[] = $described[$membershipId];
            }
        }

        return $result;
    }

    public function withOpenEnrollment(string $tenantId, array $membershipIds, array $organizationIds): array
    {
        $memberships = self::uuids($membershipIds);
        $organizations = self::uuids($organizationIds);

        if ($memberships === [] || $organizations === []) {
            return [];
        }

        $result = [];

        foreach ($this->openEnrollments($tenantId, $organizations)
            ->whereIn('s.membership_id', $memberships)
            ->distinct()
            ->pluck('s.membership_id') as $id) {
            $result[] = (string) $id;
        }

        return $result;
    }

    public function describe(string $tenantId, array $membershipIds): array
    {
        return $this->rows($tenantId, self::uuids($membershipIds), null);
    }

    /**
     * @param  list<string>  $membershipIds
     * @param  list<string>|null  $organizationIds  null = semua lembaga
     * @return array<string, array{membership_id: string, name: string, nisn: string|null, enrollments: list<array{organization_id: string, organization: string, student_number: string|null, class: string|null}>}>
     */
    private function rows(string $tenantId, array $membershipIds, ?array $organizationIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $result = [];

        foreach (DB::table('ac_students as s')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.membership_id', $membershipIds)
            ->get(['s.membership_id', 's.nisn', 'p.name']) as $row) {
            $result[(string) $row->membership_id] = [
                'membership_id' => (string) $row->membership_id,
                'name' => (string) $row->name,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'enrollments' => [],
            ];
        }

        $enrollments = DB::table('ac_enrollments as e')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->where('e.tenant_id', $tenantId)
            ->whereIn('s.membership_id', array_keys($result))
            ->whereNull('e.end_date')
            ->when($organizationIds !== null, fn (Builder $query) => $query->whereIn('e.organization_id', $organizationIds ?? []))
            ->orderBy('o.name')
            ->select(['s.membership_id', 'e.organization_id', 'o.name as organization', 'e.student_number'])
            ->selectSub(fn (Builder $class) => $class
                ->from('ac_class_members as cm')
                ->join('ac_classes as c', 'c.id', '=', 'cm.class_id')
                ->join('ac_academic_years as y', 'y.id', '=', 'cm.academic_year_id')
                ->whereColumn('cm.enrollment_id', 'e.id')
                ->whereNull('cm.end_date')
                ->orderByDesc('y.start_date')
                ->limit(1)
                ->select('c.name'), 'class_name')
            ->get();

        foreach ($enrollments as $enrollment) {
            $membershipId = (string) $enrollment->membership_id;

            if (! isset($result[$membershipId])) {
                continue;
            }

            $result[$membershipId]['enrollments'][] = [
                'organization_id' => (string) $enrollment->organization_id,
                'organization' => (string) $enrollment->organization,
                'student_number' => $enrollment->student_number === null ? null : (string) $enrollment->student_number,
                'class' => $enrollment->class_name === null ? null : (string) $enrollment->class_name,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $organizationIds
     */
    private function openEnrollments(string $tenantId, array $organizationIds): Builder
    {
        return DB::table('ac_enrollments as e')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId)
            ->whereIn('e.organization_id', $organizationIds)
            ->whereNull('e.end_date');
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function uuids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, fn (string $id): bool => Str::isUuid($id))));
    }
}
