<?php

namespace Modules\Academic\Application\Grading;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daftar siswa kelas untuk satu semester (PRD-002 §19): dipakai leger (AC-5c) dan rapor (AC-5d).
 *
 * @phpstan-import-type TermOption from ManageGrades
 */
final class TermRoster
{
    /**
     * Siswa yang pernah tercatat di kelas selama semester, ditambah yang sudah punya nilai di
     * lembar-lembar ini; urut nama. `left` = keluar dari kelas sebelum semester berakhir.
     *
     * @param  TermOption  $term
     * @param  list<string>  $sheetIds
     * @return list<array{enrollment_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, left: bool}>
     */
    public function forClass(string $classId, array $term, array $sheetIds): array
    {
        $rows = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $classId)
            ->where(function (Builder $query) use ($term, $sheetIds): void {
                $query->where(function (Builder $during) use ($term): void {
                    $during->where('cm.start_date', '<=', $term['end'])
                        ->where(function (Builder $open) use ($term): void {
                            $open->whereNull('cm.end_date')->orWhere('cm.end_date', '>', $term['start']);
                        });
                });

                if ($sheetIds !== []) {
                    $query->orWhereExists(function (Builder $graded) use ($sheetIds): void {
                        $graded->select(DB::raw(1))->from('ac_grades as g')->whereIn('g.sheet_id', $sheetIds)->whereColumn('g.enrollment_id', 'e.id');
                    });
                }
            })
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.student_number', 's.nisn', 'p.name', 'p.gender', 'cm.end_date']);

        $students = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $left = $row->end_date !== null && substr((string) $row->end_date, 0, 10) <= $term['end'];

            if (isset($students[$id])) {
                $students[$id]['left'] = $students[$id]['left'] && $left;

                continue;
            }

            $students[$id] = [
                'enrollment_id' => $id,
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'left' => $left,
            ];
        }

        return array_values($students);
    }
}
