<?php

namespace Modules\Academic\Application\Grading;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Attendance\AttendanceModeSetting;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Classroom\ManageClasses;
use Modules\Academic\Application\Subject\SubjectOrder;
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
 * - Aspek penilaian (§22 AC-8a): tiap mapel membawa nilai & predikat per aspek dari salinan aturan
 *   lembarnya; daftar aspek rapor = gabungan aspek semua mapel final (urutan kemunculan). Lebih dari
 *   satu aspek → rapor menampilkan kolom per aspek, plus jumlah & rata-rata per aspek.
 * - AC-8b (§22.4): mapel urut kelompok → urutan → nama dan membawa nama kelompoknya; mapel bernilai
 *   huruf membawa hurufnya sebagai predikat (tanpa angka); sebutan predikat ikut dikirim. Jumlah &
 *   rata-rata hanya dari mapel bernilai angka di kelompok yang ikut jumlah & rata-rata. Rata-rata
 *   keseluruhan rapor beraspek = rata-rata dari rata-rata tiap aspek (seperti contoh rapor owner).
 * - AC-8d (§22.6): ekstrakurikuler (huruf, kosong = tidak ikut) & kepribadian (huruf + keterangan
 *   otomatis per huruf) per siswa; peringkat kelas dari rata-rata rapor di antara siswa yang masih di
 *   kelas dan punya rata-rata — urut tanpa nomor kembar (OD owner 2026-11-11): rata-rata ↓, jumlah ↓,
 *   lalu nama.
 * - Catatan wali kelas maks. 1000 karakter; kosong = dihapus. Audit tanpa isi catatan.
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type TermOption from ManageGrades
 *
 * @phpstan-type AspectGrade array{score: int|null, predicate: string|null, label: string|null}
 * @phpstan-type CardSubject array{name: string, kkm: int, final: float|null, predicate: string|null, label: string|null, description: string|null, aspects: array<string, AspectGrade>, grade_type: string, group: string|null, counts: bool}
 * @phpstan-type Card array{enrollment_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, left: bool, subjects: list<CardSubject>, total: float|null, average: float|null, aspect_totals: array<string, array{total: int|null, average: float|null}>, attendance: array{S: int, I: int, A: int}, note: string|null, extracurricular: array<string, string|null>, personality: array<string, array{letter: string|null, text: string|null}>, rank: int|null}
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
        private readonly ReportItemGrades $itemGrades,
    ) {}

    /**
     * Rapor seluruh siswa kelas (atau satu siswa) pada semester itu.
     *
     * @param  TermOption  $term
     * @return array{cards: list<Card>, pending: list<string>, final_subjects: int, attendance_kind: string, homeroom: string|null, aspects: list<array{code: string, name: string}>, multi_aspect: bool, grouped: bool, predicates: list<array{letter: string, label: string|null, min: int}>, labels: bool, extracurriculars: list<array{id: string, name: string}>, personalities: list<array{id: string, name: string}>, ranked: int}
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
        $aspects = [];

        $rows = SubjectOrder::apply(
            DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('cs.class_id', $class->id),
        )->get(['cs.id', 's.name', 'sg.name as group_name', 'sg.counts_in_totals']);

        foreach ($rows as $row) {
            $sheet = $sheets->get((string) $row->id);

            if ($sheet instanceof GradeSheet && $sheet->isFinal()) {
                $rules = GradingRules::fromValue($sheet->rules);
                $subjects[] = [
                    'sheet_id' => $sheet->id,
                    'name' => (string) $row->name,
                    'rules' => $rules,
                    'letter' => $sheet->isLetter(),
                    'group' => $row->group_name === null ? null : (string) $row->group_name,
                    'counts' => $row->group_name === null || (bool) $row->counts_in_totals,
                ];

                foreach ($rules->aspects as $aspect) {
                    $aspects[$aspect['code']] ??= ['code' => $aspect['code'], 'name' => $aspect['name']];
                }
            } else {
                $pending[] = (string) $row->name;
            }
        }

        $grades = [];

        if ($subjects !== []) {
            $query = DB::table('ac_grades')->whereIn('sheet_id', array_column($subjects, 'sheet_id'));

            foreach ($query->get(['sheet_id', 'enrollment_id', 'scores', 'letters', 'final_score', 'description']) as $grade) {
                $decoded = json_decode((string) $grade->scores, true);
                $scores = [];

                foreach (is_array($decoded) ? $decoded : [] as $code => $value) {
                    $scores[(string) $code] = is_int($value) || is_float($value) ? (float) $value : null;
                }

                $grades[(string) $grade->enrollment_id][(string) $grade->sheet_id] = [
                    'final' => ManageGrades::storedFinal($grade->final_score),
                    'scores' => $scores,
                    'letters' => ManageGrades::decodeLetters($grade->letters),
                    'description' => $grade->description === null ? null : (string) $grade->description,
                ];
            }
        }

        $lesson = $this->attendanceMode->isLesson($class->tenant_id, $class->organization_id);
        $attendance = $this->attendance($class->id, $term, $lesson);
        $notes = $this->notes($class->id, $term['value']);
        $sheetIds = array_values(array_map(fn (GradeSheet $sheet): string => $sheet->id, $sheets->all()));
        $cards = [];

        $items = $this->itemGrades->items($class, $term['value']);
        $itemGrades = $this->itemGrades->grades($class->id, $term['value']);

        // Semua siswa dihitung dulu (peringkat butuh sekelas), baru disaring bila satu siswa.
        foreach ($this->roster->forClass($class->id, $term, $sheetIds) as $student) {
            $lines = [];
            $scores = [];
            $byAspect = [];

            foreach ($subjects as $subject) {
                $grade = $grades[$student['enrollment_id']][$subject['sheet_id']] ?? null;
                $rules = $subject['rules'];
                $aspectGrades = [];

                if ($subject['letter']) {
                    // Nilai huruf: hurufnya sekaligus predikat; tidak masuk jumlah & rata-rata.
                    $letters = ManageGrades::lettersFor($rules, $grade['letters'] ?? []);

                    foreach ($letters as $code => $letter) {
                        $aspectGrades[$code] = ['score' => null, 'predicate' => $letter, 'label' => $letter === null ? null : $rules->labelOf($letter)];
                    }

                    $filled = array_values(array_filter($letters, fn (?string $letter): bool => $letter !== null));
                    $single = count($filled) === 1 ? $filled[0] : null;

                    $lines[] = [
                        'name' => $subject['name'],
                        'kkm' => $rules->kkm,
                        'final' => null,
                        'predicate' => $filled === [] ? null : implode(' / ', $filled),
                        'label' => $single === null ? null : $rules->labelOf($single),
                        'description' => $grade['description'] ?? null,
                        'aspects' => $aspectGrades,
                        'grade_type' => 'LETTER',
                        'group' => $subject['group'],
                        'counts' => false,
                    ];

                    continue;
                }

                $final = $grade['final'] ?? null;

                if ($final !== null && $subject['counts']) {
                    $scores[] = $final;
                }

                foreach ($grade === null ? [] : ManageGrades::aspectScores($rules, $grade['scores']) as $code => $score) {
                    $predicate = $score === null ? null : $rules->predicate($score);
                    $aspectGrades[$code] = ['score' => $score, 'predicate' => $predicate, 'label' => $predicate === null ? null : $rules->labelOf($predicate)];

                    if ($score !== null && $subject['counts']) {
                        $byAspect[$code][] = $score;
                    }
                }

                $predicate = $final === null ? null : $rules->predicate($final);

                $lines[] = [
                    'name' => $subject['name'],
                    'kkm' => $rules->kkm,
                    'final' => $final,
                    'predicate' => $predicate,
                    'label' => $predicate === null ? null : $rules->labelOf($predicate),
                    'description' => $grade['description'] ?? null,
                    'aspects' => $aspectGrades,
                    'grade_type' => 'NUMBER',
                    'group' => $subject['group'],
                    'counts' => $subject['counts'],
                ];
            }

            $aspectTotals = [];
            $aspectAverages = [];

            foreach (array_keys($aspects) as $code) {
                $values = $byAspect[$code] ?? [];
                $average = GradeLedger::average($values);
                $aspectTotals[$code] = ['total' => $values === [] ? null : array_sum($values), 'average' => $average];

                if ($average !== null) {
                    $aspectAverages[] = $average;
                }
            }

            $cards[] = $student + [
                'subjects' => $lines,
                'total' => $scores === [] ? null : round(array_sum($scores), 1),
                // Rapor beraspek: rata-rata dari rata-rata tiap aspek (OD owner 2026-11-10).
                'average' => count($aspects) > 1 ? GradeLedger::average($aspectAverages) : GradeLedger::average($scores),
                'aspect_totals' => $aspectTotals,
                'attendance' => $attendance[$student['enrollment_id']] ?? ['S' => 0, 'I' => 0, 'A' => 0],
                'note' => $notes[$student['enrollment_id']] ?? null,
            ] + $this->itemsFor($items, $itemGrades[$student['enrollment_id']] ?? []);
        }

        $ranks = self::ranks($cards);
        $ranked = count($ranks);
        $cards = array_map(fn (array $card, int $index): array => array_merge($card, ['rank' => $ranks[$index] ?? null]), $cards, array_keys($cards));

        if ($enrollmentId !== null) {
            $cards = array_values(array_filter($cards, fn (array $card): bool => $card['enrollment_id'] === $enrollmentId));
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
            'aspects' => array_values($aspects),
            'multi_aspect' => count($aspects) > 1,
            'grouped' => array_filter(array_column($subjects, 'group')) !== [],
            // Skala predikat untuk kotak keterangan (dari lembar final pertama; satu lembaga = satu skala).
            'predicates' => $subjects === [] ? [] : $subjects[0]['rules']->predicates,
            'labels' => $subjects !== [] && $subjects[0]['rules']->hasLabels(),
            'extracurriculars' => array_values(array_map(fn (array $item): array => ['id' => $item['id'], 'name' => $item['name']], array_filter($items, fn (array $item): bool => $item['kind'] === 'EKSKUL'))),
            'personalities' => array_values(array_map(fn (array $item): array => ['id' => $item['id'], 'name' => $item['name']], array_filter($items, fn (array $item): bool => $item['kind'] === 'KEPRIBADIAN'))),
            'ranked' => $ranked,
        ];
    }

    /**
     * Ekstrakurikuler & kepribadian satu siswa.
     *
     * @param  list<array{id: string, kind: string, name: string, descriptions: array<string, string>}>  $items
     * @param  array<string, array{letter: string|null, note: string|null}>  $grades
     * @return array{extracurricular: array<string, string|null>, personality: array<string, array{letter: string|null, text: string|null}>, rank: null}
     */
    private function itemsFor(array $items, array $grades): array
    {
        $extracurricular = [];
        $personality = [];

        foreach ($items as $item) {
            $grade = $grades[$item['id']] ?? ['letter' => null, 'note' => null];

            if ($item['kind'] === 'EKSKUL') {
                $extracurricular[$item['id']] = $grade['letter'];
            } else {
                $personality[$item['id']] = ['letter' => $grade['letter'], 'text' => ReportItemGrades::personalityText($item['descriptions'], $grade['letter'], $grade['note'])];
            }
        }

        return ['extracurricular' => $extracurricular, 'personality' => $personality, 'rank' => null];
    }

    /**
     * Peringkat siswa yang masih di kelas dan punya rata-rata: urut rata-rata ↓, jumlah ↓, lalu nama;
     * nomor berurutan tanpa kembar (OD owner 2026-11-11).
     *
     * @param  list<array{name: string, left: bool, average: float|null, total: float|null}>  $cards
     * @return array<int, int> indeks kartu → peringkat
     */
    public static function ranks(array $cards): array
    {
        $eligible = array_keys(array_filter($cards, fn (array $card): bool => ! $card['left'] && $card['average'] !== null));

        usort($eligible, function (int $a, int $b) use ($cards): int {
            return [$cards[$b]['average'], $cards[$b]['total'] ?? 0.0, $cards[$a]['name']] <=> [$cards[$a]['average'], $cards[$a]['total'] ?? 0.0, $cards[$b]['name']];
        });

        $ranks = [];

        foreach ($eligible as $position => $index) {
            $ranks[$index] = $position + 1;
        }

        return $ranks;
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
