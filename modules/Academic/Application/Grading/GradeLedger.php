<?php

namespace Modules\Academic\Application\Grading;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Grading\GradeSheet;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Leger nilai kelas per semester (PRD-002 §19 AC-5c): siswa × mapel berisi nilai akhir, rata-rata
 * per siswa & per mapel, jumlah nilai di bawah KKM per siswa, dan mapel yang belum final.
 *
 * - Nilai dari lembar DRAFT ikut tampil tetapi ditandai; rapor (AC-5d) hanya memakai lembar FINAL.
 * - KKM tiap mapel = KKM salinan aturan lembarnya; mapel tanpa lembar memakai aturan yang berlaku.
 * - Rata-rata hanya dari nilai akhir yang ada, dibulatkan 1 desimal [ASUMSI].
 * - Siswa = yang pernah tercatat di kelas pada semester itu + yang sudah punya nilai di kelas itu.
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type TermOption from ManageGrades
 *
 * @phpstan-type LedgerSubject array{id: string, name: string, code: string|null, status: string, kkm: int}
 * @phpstan-type LedgerStudent array{enrollment_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, left: bool, grades: array<string, int|null>, average: float|null, below: int}
 * @phpstan-type Ledger array{subjects: list<LedgerSubject>, students: list<LedgerStudent>, subject_averages: array<string, float|null>, class_average: float|null, final_count: int}
 */
final class GradeLedger
{
    public function __construct(
        private readonly GradingSetting $grading,
    ) {}

    /**
     * @param  TermOption  $term
     * @return Ledger
     */
    public function build(SchoolClass $class, array $term): array
    {
        $current = $this->grading->rulesFor($class->tenant_id, $class->organization_id);
        $sheets = GradeSheet::query()
            ->withoutGlobalScope('tenant')
            ->where('class_id', $class->id)
            ->where('term_id', $term['value'])
            ->get()
            ->keyBy('class_subject_id');

        $subjects = [];
        $finalCount = 0;

        $rows = DB::table('ac_class_subjects as cs')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.class_id', $class->id)
            ->orderByRaw('lower(s.name)')
            ->get(['cs.id', 's.name', 's.code']);

        foreach ($rows as $row) {
            $sheet = $sheets->get((string) $row->id);
            $status = $sheet instanceof GradeSheet ? $sheet->status : ManageGrades::EMPTY;
            $finalCount += $status === GradeSheet::FINAL ? 1 : 0;

            $subjects[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'code' => $row->code === null ? null : (string) $row->code,
                'status' => $status,
                'kkm' => $sheet instanceof GradeSheet ? GradingRules::fromValue($sheet->rules)->kkm : $current->kkm,
            ];
        }

        // Nilai akhir per siswa per mapel kelas.
        $finals = [];
        $sheetIds = array_values(array_map(fn (GradeSheet $sheet): string => $sheet->id, $sheets->all()));

        if ($sheetIds !== []) {
            $grades = DB::table('ac_grades as g')
                ->join('ac_grade_sheets as gs', 'gs.id', '=', 'g.sheet_id')
                ->whereIn('g.sheet_id', $sheetIds)
                ->get(['g.enrollment_id', 'gs.class_subject_id', 'g.final_score']);

            foreach ($grades as $grade) {
                $finals[(string) $grade->enrollment_id][(string) $grade->class_subject_id] = $grade->final_score === null ? null : (int) $grade->final_score;
            }
        }

        $students = [];
        $subjectTotals = [];
        $classScores = [];

        foreach ($this->roster($class->id, $term, $sheetIds) as $student) {
            $grades = [];
            $mine = [];
            $below = 0;

            foreach ($subjects as $subject) {
                $score = $finals[$student['enrollment_id']][$subject['id']] ?? null;
                $grades[$subject['id']] = $score;

                if ($score !== null) {
                    $mine[] = $score;
                    $classScores[] = $score;
                    $subjectTotals[$subject['id']][] = $score;
                    $below += $score < $subject['kkm'] ? 1 : 0;
                }
            }

            $students[] = $student + [
                'grades' => $grades,
                'average' => self::average($mine),
                'below' => $below,
            ];
        }

        $subjectAverages = [];

        foreach ($subjects as $subject) {
            $subjectAverages[$subject['id']] = self::average($subjectTotals[$subject['id']] ?? []);
        }

        return [
            'subjects' => $subjects,
            'students' => $students,
            'subject_averages' => $subjectAverages,
            'class_average' => self::average($classScores),
            'final_count' => $finalCount,
        ];
    }

    public static function fileName(SchoolClass $class, string $termLabel, string $yearName): string
    {
        return 'leger-'.Str::slug($class->name).'-'.Str::slug($termLabel).'-'.Str::slug($yearName).'.xlsx';
    }

    /**
     * Tulis leger ke .xlsx: satu baris per siswa, kolom per mapel; judul mapel yang belum final
     * diberi tanda "(draf)" atau "(belum ada)".
     *
     * @param  Ledger  $ledger
     */
    public function write(array $ledger, string $path): void
    {
        $header = ['No', 'Nama', 'NISN', 'Nomor induk'];
        $kkmRow = ['', 'KKM', '', ''];

        foreach ($ledger['subjects'] as $subject) {
            $header[] = $subject['name'].match ($subject['status']) {
                GradeSheet::FINAL => '',
                GradeSheet::DRAFT => ' (draf)',
                default => ' (belum ada)',
            };
            $kkmRow[] = $subject['kkm'];
        }

        $header = array_merge($header, ['Rata-rata', 'Di bawah KKM']);
        $kkmRow = array_merge($kkmRow, ['', '']);

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Leger');
            $sheet->setSheetView(new SheetView(freezeRow: 3));
            foreach ([1 => 5, 2 => 32, 3 => 14, 4 => 14] as $column => $width) {
                $sheet->setColumnWidth($width, $column);
            }

            $bold = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');
            $text = (new Style)->withFormat('@');

            $writer->addRow(Row::fromValuesWithStyle($header, $bold));
            $writer->addRow(Row::fromValues($kkmRow));

            foreach ($ledger['students'] as $index => $student) {
                $cells = [
                    Cell::fromValue($index + 1),
                    Cell::fromValue($student['name'].($student['left'] ? ' (keluar)' : '')),
                    Cell::fromValue($student['nisn'] ?? '', $text),
                    Cell::fromValue($student['student_number'] ?? '', $text),
                ];

                foreach ($ledger['subjects'] as $subject) {
                    $cells[] = Cell::fromValue($student['grades'][$subject['id']] ?? '');
                }

                $cells[] = Cell::fromValue($student['average'] ?? '');
                $cells[] = Cell::fromValue($student['below']);
                $writer->addRow(new Row($cells));
            }

            $averages = ['', 'Rata-rata mapel', '', ''];

            foreach ($ledger['subjects'] as $subject) {
                $averages[] = $ledger['subject_averages'][$subject['id']] ?? '';
            }

            $averages[] = $ledger['class_average'] ?? '';
            $averages[] = '';
            $writer->addRow(Row::fromValuesWithStyle($averages, (new Style)->withFontBold(true)));
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  list<int>  $scores
     */
    private static function average(array $scores): ?float
    {
        return $scores === [] ? null : round(array_sum($scores) / count($scores), 1);
    }

    /**
     * @param  TermOption  $term
     * @param  list<string>  $sheetIds
     * @return list<array{enrollment_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, left: bool}>
     */
    private function roster(string $classId, array $term, array $sheetIds): array
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
