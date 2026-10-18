<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Domain\Student\EnrollmentEndReason;
use stdClass;

/**
 * Daftar siswa dengan pencarian & saringan (PRD-002 §6, AC-1c-a). Semua saringan, termasuk hak
 * lihat (StudentVisibility), dijalankan di database sebelum paginasi.
 *
 * @phpstan-type EnrollmentSummary array{organization: string, student_number: string|null, open: bool, end_reason: string|null}
 * @phpstan-type StudentRow array{id: string, name: string, nisn: string|null, status: string, status_label: string, enrollments: list<EnrollmentSummary>, other_active: int}
 */
final class StudentDirectory
{
    public const STATUS_ACTIVE = 'AKTIF';

    public const STATUS_ENDED = 'KELUAR';

    public function __construct(
        private readonly StudentVisibility $visibility,
    ) {}

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function search(StudentFilters $filters): LengthAwarePaginator
    {
        $query = DB::table('ac_students as s')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id');

        $this->visibility->apply($query, AcademicAccess::STUDENTS_VIEW);
        $this->applyFilters($query, $filters, $this->visibility->nodeIds(AcademicAccess::STUDENTS_VIEW));

        /** @var LengthAwarePaginator<int, stdClass> $page */
        $page = $query
            ->orderBy('p.name')
            ->orderBy('s.id')
            ->select(['s.id', 's.nisn', 'p.name'])
            ->paginate($filters->perPage);

        return $page->withQueryString();
    }

    /**
     * Ringkasan per baris: pendaftaran di lembaga yang terlihat, dan jumlah pendaftaran aktif di
     * lembaga lain (mis. SMP Unit 3 + "1 lembaga lain").
     *
     * @param  list<stdClass>  $rows  baris dari search()
     * @return list<StudentRow>
     */
    public function present(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (string) $row->id;
        }

        if ($ids === []) {
            return [];
        }

        $scope = $this->visibility->nodeIds(AcademicAccess::STUDENTS_VIEW);
        $inScope = $scope === null ? null : array_flip($scope);

        $enrollments = DB::table('ac_enrollments as e')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->whereIn('e.student_id', $ids)
            ->orderByRaw('e.end_date IS NOT NULL')
            ->orderBy('o.name')
            ->orderByDesc('e.start_date')
            ->get(['e.student_id', 'e.organization_id', 'e.student_number', 'e.end_date', 'e.end_reason', 'o.name as organization']);

        $visible = [];
        $otherActive = [];

        foreach ($enrollments as $enrollment) {
            $studentId = (string) $enrollment->student_id;
            $open = $enrollment->end_date === null;

            if ($inScope !== null && ! isset($inScope[(string) $enrollment->organization_id])) {
                $otherActive[$studentId] = ($otherActive[$studentId] ?? 0) + ($open ? 1 : 0);

                continue;
            }

            $reason = $enrollment->end_reason === null ? null : EnrollmentEndReason::tryFrom((string) $enrollment->end_reason);

            $visible[$studentId][] = [
                'organization' => (string) $enrollment->organization,
                'student_number' => $enrollment->student_number === null ? null : (string) $enrollment->student_number,
                'open' => $open,
                'end_reason' => $reason?->label(),
            ];
        }

        $result = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $own = $visible[$id] ?? [];
            $active = false;

            foreach ($own as $item) {
                $active = $active || $item['open'];
            }

            $result[] = [
                'id' => $id,
                'name' => (string) $row->name,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'status' => $active ? self::STATUS_ACTIVE : self::STATUS_ENDED,
                'status_label' => $active ? 'Aktif' : 'Keluar/lulus',
                'enrollments' => $own,
                'other_active' => $otherActive[$id] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>|null  $scope  node yang terlihat; null = seluruh yayasan
     */
    private function applyFilters(Builder $query, StudentFilters $filters, ?array $scope): void
    {
        if ($filters->search !== '') {
            $pattern = '%'.addcslashes($filters->search, '%_\\').'%';

            $query->where(function (Builder $search) use ($pattern): void {
                $search->where('p.name', 'ilike', $pattern)
                    ->orWhere('s.nisn', 'ilike', $pattern)
                    ->orWhereExists(function (Builder $numbers) use ($pattern): void {
                        $numbers->select(DB::raw(1))
                            ->from('ac_enrollments as sn')
                            ->whereColumn('sn.student_id', 's.id')
                            ->where('sn.student_number', 'ilike', $pattern);
                    });
            });
        }

        // Pendaftaran yang dihitung: di lembaga yang terlihat, dan (bila disaring) di lembaga itu
        // atau turunannya (mis. Unit 3 → SMP Unit 3, MDA Unit 3).
        $restrict = function (Builder $enrollments, bool $openOnly) use ($filters, $scope): void {
            $enrollments->select(DB::raw(1))
                ->from('ac_enrollments as fe')
                ->whereColumn('fe.student_id', 's.id');

            if ($scope !== null) {
                $enrollments->whereIn('fe.organization_id', $scope);
            }

            if ($filters->organizationId !== null) {
                $enrollments->whereIn('fe.organization_id', function (Builder $closure) use ($filters): void {
                    $closure->select('descendant_id')
                        ->from('organization_closure')
                        ->where('ancestor_id', $filters->organizationId);
                });
            }

            if ($openOnly) {
                $enrollments->whereNull('fe.end_date');
            }
        };

        $anyEnrollment = function (Builder $enrollments) use ($restrict): void {
            $restrict($enrollments, false);
        };
        $openEnrollment = function (Builder $enrollments) use ($restrict): void {
            $restrict($enrollments, true);
        };

        if ($filters->status === StudentFilters::STATUS_ACTIVE) {
            $query->whereExists($openEnrollment);

            return;
        }

        $query->whereExists($anyEnrollment);

        if ($filters->status === StudentFilters::STATUS_ENDED) {
            $query->whereNotExists($openEnrollment);
        }
    }
}
