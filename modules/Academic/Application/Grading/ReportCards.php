<?php

namespace Modules\Academic\Application\Grading;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Attendance\AttendanceModeSetting;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Classroom\ManageClasses;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Academic\Domain\Grading\GradeSheet;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Rapor per siswa per semester (PRD-002 §19 AC-5d) dan catatan wali kelas.
 *
 * - Hanya mapel dengan lembar nilai **FINAL** yang masuk rapor; mapel lain disebut sebagai peringatan.
 * - Presensi = rekap S/I/A semester itu dari presensi harian; lembaga yang memakai presensi per
 *   pertemuan memakai rekap pertemuan [ASUMSI].
 * - Jumlah & rata-rata nilai rapor dari mapel final yang punya nilai [ASUMSI].
 * - Catatan wali kelas maks. 1000 karakter; kosong = dihapus. Audit tanpa isi catatan.
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type TermOption from ManageGrades
 *
 * @phpstan-type CardSubject array{name: string, kkm: int, final: int|null, predicate: string|null, description: string|null}
 * @phpstan-type Card array{enrollment_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, left: bool, subjects: list<CardSubject>, total: int|null, average: float|null, attendance: array{S: int, I: int, A: int}, note: string|null}
 */
final class ReportCards
{
    public const NOTE_MAX_LENGTH = 1000;

    public function __construct(
        private readonly TermRoster $roster,
        private readonly ManageGrades $grades,
        private readonly AttendanceModeSetting $attendanceMode,
        private readonly ActiveEmployees $employees,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Rapor seluruh siswa kelas (atau satu siswa) pada semester itu.
     *
     * @param  TermOption  $term
     * @return array{cards: list<Card>, pending: list<string>, final_subjects: int, attendance_kind: string, homeroom: string|null}
     */
    public function build(SchoolClass $class, array $term, ?string $enrollmentId = null): array
    {
        $sheets = GradeSheet::query()
            ->withoutGlobalScope('tenant')
            ->where('class_id', $class->id)
            ->where('term_id', $term['value'])
            ->get()
            ->keyBy('class_subject_id');

        $subjects = [];
        $pending = [];

        $rows = DB::table('ac_class_subjects as cs')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.class_id', $class->id)
            ->orderByRaw('lower(s.name)')
            ->get(['cs.id', 's.name']);

        foreach ($rows as $row) {
            $sheet = $sheets->get((string) $row->id);

            if ($sheet instanceof GradeSheet && $sheet->isFinal()) {
                $subjects[] = ['sheet_id' => $sheet->id, 'name' => (string) $row->name, 'rules' => GradingRules::fromValue($sheet->rules)];
            } else {
                $pending[] = (string) $row->name;
            }
        }

        $grades = [];

        if ($subjects !== []) {
            $query = DB::table('ac_grades')->whereIn('sheet_id', array_column($subjects, 'sheet_id'));

            foreach ($query->get(['sheet_id', 'enrollment_id', 'final_score', 'description']) as $grade) {
                $grades[(string) $grade->enrollment_id][(string) $grade->sheet_id] = [
                    'final' => $grade->final_score === null ? null : (int) $grade->final_score,
                    'description' => $grade->description === null ? null : (string) $grade->description,
                ];
            }
        }

        $lesson = $this->attendanceMode->isLesson($class->tenant_id, $class->organization_id);
        $attendance = $this->attendance($class->id, $term, $lesson);
        $notes = $this->notes($class->id, $term['value']);
        $sheetIds = array_values(array_map(fn (GradeSheet $sheet): string => $sheet->id, $sheets->all()));
        $cards = [];

        foreach ($this->roster->forClass($class->id, $term, $sheetIds) as $student) {
            if ($enrollmentId !== null && $student['enrollment_id'] !== $enrollmentId) {
                continue;
            }

            $lines = [];
            $scores = [];

            foreach ($subjects as $subject) {
                $grade = $grades[$student['enrollment_id']][$subject['sheet_id']] ?? null;
                $final = $grade['final'] ?? null;

                if ($final !== null) {
                    $scores[] = $final;
                }

                $lines[] = [
                    'name' => $subject['name'],
                    'kkm' => $subject['rules']->kkm,
                    'final' => $final,
                    'predicate' => $final === null ? null : $subject['rules']->predicate($final),
                    'description' => $grade['description'] ?? null,
                ];
            }

            $cards[] = $student + [
                'subjects' => $lines,
                'total' => $scores === [] ? null : array_sum($scores),
                'average' => GradeLedger::average($scores),
                'attendance' => $attendance[$student['enrollment_id']] ?? ['S' => 0, 'I' => 0, 'A' => 0],
                'note' => $notes[$student['enrollment_id']] ?? null,
            ];
        }

        $homeroom = $class->homeroom_employee_id === null
            ? null
            : ($this->employees->describe($class->tenant_id, [$class->homeroom_employee_id])[$class->homeroom_employee_id]['name'] ?? null);

        return [
            'cards' => $cards,
            'pending' => $pending,
            'final_subjects' => count($subjects),
            'attendance_kind' => $lesson ? 'pertemuan' : 'hari',
            'homeroom' => $homeroom,
        ];
    }

    /**
     * Simpan catatan wali kelas beberapa siswa sekaligus; catatan kosong = dihapus.
     *
     * @param  array<string, mixed>  $entries  id pendaftaran => catatan
     * @return int jumlah catatan yang berubah
     *
     * @throws GradingException
     */
    public function saveNotes(string $tenantId, string $classId, string $termId, array $entries, string $membershipId): int
    {
        if ($entries === []) {
            throw GradingException::make('notes', 'Belum ada catatan yang diisi.');
        }

        return DB::transaction(function () use ($tenantId, $classId, $termId, $entries, $membershipId): int {
            $class = Str::isUuid($classId)
                ? SchoolClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classId)->lockForUpdate()->first()
                : null;

            if (! $class instanceof SchoolClass) {
                throw GradingException::make('form', 'Kelas tidak ditemukan di yayasan ini.');
            }

            $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

            if (ManageClasses::isPastYear($year)) {
                throw GradingException::make('form', 'Tahun ajaran kelas ini sudah lewat; rapornya hanya bisa dilihat.');
            }

            $term = null;

            foreach ($this->grades->terms($class) as $option) {
                if ($option['value'] === $termId) {
                    $term = $option;
                }
            }

            if ($term === null) {
                throw GradingException::make('form', 'Semester tidak ditemukan di tahun ajaran kelas ini.');
            }

            $roster = [];

            foreach ($this->roster->forClass($class->id, $term, []) as $student) {
                $roster[$student['enrollment_id']] = $student['name'];
            }

            if (array_diff_key($entries, $roster) !== []) {
                throw GradingException::make('notes', 'Daftar siswa berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.');
            }

            $saved = $this->notes($class->id, $term['value']);
            $now = CarbonImmutable::now();
            $changed = 0;

            foreach ($entries as $enrollmentId => $input) {
                $note = is_string($input) ? trim($input) : '';

                if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
                    throw GradingException::make('notes', "Catatan untuk {$roster[$enrollmentId]} maksimal ".self::NOTE_MAX_LENGTH.' karakter.');
                }

                $before = $saved[$enrollmentId] ?? null;

                if ($note === ($before ?? '')) {
                    continue;
                }

                $changed++;
                $key = ['class_id' => $class->id, 'term_id' => $term['value'], 'enrollment_id' => $enrollmentId];

                if ($note === '') {
                    DB::table('ac_report_notes')->where($key)->delete();
                } elseif ($before === null) {
                    DB::table('ac_report_notes')->insert($key + [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'organization_id' => $class->organization_id,
                        'academic_year_id' => $class->academic_year_id,
                        'note' => $note,
                        'updated_by_membership_id' => $membershipId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('ac_report_notes')->where($key)->update(['note' => $note, 'updated_by_membership_id' => $membershipId, 'updated_at' => $now]);
                }
            }

            if ($changed > 0) {
                $this->audit->record(
                    AcademicAuditAction::REPORT_NOTES_SAVED,
                    AcademicAuditAction::SUBJECT_CLASS,
                    $class->id,
                    ['kelas' => $class->name, 'semester' => $term['label'], 'jumlah' => $changed],
                    organizationId: $class->organization_id,
                    tenantId: $tenantId,
                );
            }

            return $changed;
        });
    }

    /**
     * @return array<string, string> id pendaftaran => catatan
     */
    private function notes(string $classId, string $termId): array
    {
        $notes = [];

        foreach (DB::table('ac_report_notes')->where('class_id', $classId)->where('term_id', $termId)->get(['enrollment_id', 'note']) as $row) {
            $notes[(string) $row->enrollment_id] = (string) $row->note;
        }

        return $notes;
    }

    /**
     * Rekap S/I/A per siswa pada semester itu (harian, atau per pertemuan bila $lesson).
     *
     * @param  TermOption  $term
     * @return array<string, array{S: int, I: int, A: int}>
     */
    private function attendance(string $classId, array $term, bool $lesson): array
    {
        $query = DB::table('ac_attendance_records as r')
            ->join('ac_attendance_sessions as s', 's.id', '=', 'r.session_id')
            ->where('s.class_id', $classId)
            ->whereBetween('s.date', [$term['start'], $term['end']])
            ->whereIn('r.status', ['S', 'I', 'A']);

        if ($lesson) {
            $query->whereNotNull('s.class_subject_id');
        } else {
            $query->whereNull('s.class_subject_id');
        }

        $counts = [];

        foreach ($query->groupBy('r.enrollment_id', 'r.status')->selectRaw('r.enrollment_id, r.status, count(*) AS total')->get() as $row) {
            $id = (string) $row->enrollment_id;
            $counts[$id] ??= ['S' => 0, 'I' => 0, 'A' => 0];
            $status = (string) $row->status;

            if ($status === 'S' || $status === 'I' || $status === 'A') {
                $counts[$id][$status] = (int) $row->total;
            }
        }

        return $counts;
    }
}
