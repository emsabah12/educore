<?php

namespace Modules\Academic\Application\Grading;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Classroom\ManageClasses;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Academic\Domain\Grading\GradeSheet;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;

/**
 * Lembar nilai satu mapel kelas per semester (PRD-002 §19 AC-5b).
 *
 * Aturan [OWNER + ASUMSI disetujui, 2026-10-22]:
 * - Nilai per komponen 0–100 (maks. 2 desimal, boleh kosong); nilai akhir = Σ nilai × bobot ÷ 100,
 *   dibulatkan ke bilangan bulat terdekat (setengah ke atas); kosong bila ada komponen kosong.
 * - Lembar menyimpan salinan aturan penilaian saat pertama kali disimpan; aturan yang berubah
 *   sesudahnya tidak mengubah lembar, kecuali operator menekan "Terapkan aturan terbaru" (DRAFT).
 * - Siswa = yang pernah tercatat di kelas pada semester itu (+ yang sudah punya nilai). Yang keluar
 *   dari kelas sebelum semester berakhir ditandai dan tidak wajib dinilai untuk Finalkan.
 * - FINAL = hanya dibaca; dibuka kembali hanya oleh pengelola kelas (alasan wajib).
 * - Kelas tahun ajaran yang sudah lewat hanya dibaca.
 *
 * Hak akses (pengelola kelas atau guru pengampu mapel itu) dicek pemanggil.
 *
 * @phpstan-import-type Component from GradingRules
 *
 * @phpstan-type TermOption array{value: string, label: string, start: string, end: string}
 * @phpstan-type GradeRow array{enrollment_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, left: bool, scores: array<string, float|null>, final: int|null, predicate: string|null, description: string|null}
 * @phpstan-type SheetData array{status: string, rules: array{kkm: int, grade_a: int, grade_b: int, components: list<Component>}, outdated: bool, finalized: array{by: string|null, at: string}|null, students: list<GradeRow>, complete: int, required: int}
 */
final class ManageGrades
{
    public const MAX_STUDENTS = 200;

    public const DESCRIPTION_MAX_LENGTH = 300;

    public const REASON_MAX_LENGTH = 500;

    /** Status lembar yang belum pernah disimpan (tidak ada barisnya). */
    public const EMPTY = 'BELUM';

    private const SCORE_PATTERN = '/^\d{1,3}(\.\d{1,2})?$/';

    public function __construct(
        private readonly GradingSetting $grading,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Semester tahun ajaran kelas, urut.
     *
     * @return list<TermOption>
     */
    public function terms(SchoolClass $class): array
    {
        $terms = [];

        foreach (Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $class->academic_year_id)->orderBy('sequence')->get() as $term) {
            $terms[] = [
                'value' => $term->id,
                'label' => $term->name,
                'start' => $term->start_date->toDateString(),
                'end' => $term->end_date->toDateString(),
            ];
        }

        return $terms;
    }

    /**
     * Semester dari URL bila ada di pilihan; selain itu semester yang memuat hari ini, lalu semester
     * terakhir yang sudah mulai, lalu semester pertama.
     *
     * @param  list<TermOption>  $terms
     * @return TermOption|null
     */
    public static function pickTerm(array $terms, mixed $requested): ?array
    {
        $today = BusinessClock::todayString();
        $started = null;

        foreach ($terms as $term) {
            if (is_string($requested) && $term['value'] === $requested) {
                return $term;
            }
        }

        foreach ($terms as $term) {
            if ($term['start'] <= $today && $term['end'] >= $today) {
                return $term;
            }

            if ($term['start'] <= $today) {
                $started = $term;
            }
        }

        return $started ?? ($terms[0] ?? null);
    }

    /**
     * Isi lembar nilai untuk ditampilkan: aturan (salinan lembar, atau aturan berlaku bila belum
     * ada), siswa beserta nilainya, dan jumlah yang lengkap.
     *
     * @param  TermOption  $term
     * @return SheetData
     */
    public function sheet(ClassSubject $row, array $term): array
    {
        $sheet = $this->findSheet($row->id, $term['value']);
        $current = $this->grading->rulesFor($row->tenant_id, $row->organization_id);
        $rules = $sheet === null ? $current : GradingRules::fromValue($sheet->rules);
        $saved = $sheet === null ? [] : $this->savedGrades($sheet->id);
        $students = [];
        $complete = 0;
        $required = 0;

        foreach ($this->roster($row->class_id, $term, $sheet?->id) as $student) {
            $grade = $saved[$student['enrollment_id']] ?? null;
            $scores = [];

            foreach ($rules->components as $component) {
                $scores[$component['code']] = $grade['scores'][$component['code']] ?? null;
            }

            $final = $grade['final'] ?? null;
            $complete += $final === null ? 0 : 1;
            $required += $student['left'] ? 0 : 1;

            $students[] = $student + [
                'scores' => $scores,
                'final' => $final,
                'predicate' => $final === null ? null : $rules->predicate($final),
                'description' => $grade['description'] ?? null,
            ];
        }

        return [
            'status' => $sheet === null ? self::EMPTY : $sheet->status,
            'rules' => $rules->toArray(),
            'outdated' => $sheet !== null && ! $sheet->isFinal() && $rules->toArray() !== $current->toArray(),
            'finalized' => $sheet === null || $sheet->finalized_at === null ? null : [
                'by' => $sheet->finalized_by_membership_id === null ? null : $this->memberName($sheet->finalized_by_membership_id),
                'at' => $sheet->finalized_at->toIso8601String(),
            ],
            'students' => $students,
            'complete' => $complete,
            'required' => $required,
        ];
    }

    /**
     * Simpan nilai siswa yang dikirim (siswa lain tidak diubah).
     *
     * @param  array<string, array{scores: mixed, description: mixed}>  $entries  id pendaftaran => isian
     * @return array{saved: int, complete: int}
     *
     * @throws GradingException
     */
    public function save(string $tenantId, string $classSubjectId, string $termId, array $entries): array
    {
        if ($entries === []) {
            throw GradingException::make('grades', 'Belum ada nilai yang diisi.');
        }

        if (count($entries) > self::MAX_STUDENTS) {
            throw GradingException::make('grades', 'Paling banyak '.self::MAX_STUDENTS.' siswa sekali simpan.');
        }

        return $this->guard(function () use ($tenantId, $classSubjectId, $termId, $entries): array {
            [$row, $class, $term, $subjectName] = $this->lockEditable($tenantId, $classSubjectId, $termId);
            $sheet = $this->findSheet($row->id, $term['value'], lock: true);

            if ($sheet?->isFinal()) {
                throw GradingException::make('form', 'Nilai ini sudah difinalkan. Minta operator membuka kembali bila perlu diubah.');
            }

            $roster = [];

            foreach ($this->roster($class->id, $term, $sheet?->id) as $student) {
                $roster[$student['enrollment_id']] = $student['name'];
            }

            if ($roster === []) {
                throw GradingException::make('grades', 'Belum ada siswa di kelas ini pada semester tersebut.');
            }

            if (array_diff_key($entries, $roster) !== []) {
                throw GradingException::make('grades', 'Daftar siswa berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.');
            }

            if ($sheet === null) {
                $sheet = new GradeSheet;
                $sheet->fill([
                    'tenant_id' => $tenantId,
                    'organization_id' => $class->organization_id,
                    'academic_year_id' => $class->academic_year_id,
                    'class_id' => $class->id,
                    'class_subject_id' => $row->id,
                    'term_id' => $term['value'],
                    'status' => GradeSheet::DRAFT,
                    'rules' => $this->grading->rulesFor($tenantId, $class->organization_id)->toArray(),
                ]);
                $sheet->save();
            }

            $rules = GradingRules::fromValue($sheet->rules);
            $now = CarbonImmutable::now();

            foreach ($entries as $enrollmentId => $entry) {
                $scores = $this->validatedScores($rules, $entry['scores'], $roster[$enrollmentId]);
                $description = $this->validatedDescription($entry['description'], $roster[$enrollmentId]);
                $values = [
                    'scores' => json_encode((object) $scores, JSON_THROW_ON_ERROR),
                    'final_score' => self::finalScore($rules, $scores),
                    'description' => $description,
                    'updated_at' => $now,
                ];

                $updated = DB::table('ac_grades')->where('sheet_id', $sheet->id)->where('enrollment_id', $enrollmentId)->update($values);

                if ($updated === 0) {
                    DB::table('ac_grades')->insert($values + [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'organization_id' => $class->organization_id,
                        'sheet_id' => $sheet->id,
                        'enrollment_id' => $enrollmentId,
                        'created_at' => $now,
                    ]);
                }
            }

            $sheet->touch();
            $complete = DB::table('ac_grades')->where('sheet_id', $sheet->id)->whereNotNull('final_score')->count();

            $this->audit->record(
                AcademicAuditAction::GRADES_SAVED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'mapel' => $subjectName, 'semester' => $term['label'], 'siswa' => count($entries), 'lengkap' => $complete],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return ['saved' => count($entries), 'complete' => $complete];
        });
    }

    /**
     * Finalkan: semua siswa yang masih di kelas wajib punya nilai akhir.
     *
     * @throws GradingException
     */
    public function finalize(string $tenantId, string $classSubjectId, string $termId, string $membershipId): void
    {
        DB::transaction(function () use ($tenantId, $classSubjectId, $termId, $membershipId): void {
            [$row, $class, $term, $subjectName] = $this->lockEditable($tenantId, $classSubjectId, $termId);
            $sheet = $this->findSheet($row->id, $term['value'], lock: true);

            if ($sheet === null) {
                throw GradingException::make('form', 'Belum ada nilai yang disimpan untuk mapel ini.');
            }

            if ($sheet->isFinal()) {
                throw GradingException::make('form', 'Nilai ini sudah difinalkan.');
            }

            $saved = $this->savedGrades($sheet->id);
            $missing = [];
            $students = 0;

            foreach ($this->roster($class->id, $term, $sheet->id) as $student) {
                if ($student['left']) {
                    continue;
                }

                $students++;

                if (($saved[$student['enrollment_id']]['final'] ?? null) === null) {
                    $missing[] = $student['name'];
                }
            }

            if ($missing !== []) {
                $listed = implode(', ', array_slice($missing, 0, 5)).(count($missing) > 5 ? ', dan lainnya' : '');

                throw GradingException::make('form', 'Nilai belum lengkap untuk '.count($missing)." siswa: {$listed}. Lengkapi semua komponen lalu simpan dulu.");
            }

            $sheet->status = GradeSheet::FINAL;
            $sheet->finalized_by_membership_id = $membershipId;
            $sheet->finalized_at = CarbonImmutable::now();
            $sheet->save();

            $this->audit->record(
                AcademicAuditAction::GRADE_SHEET_FINALIZED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'mapel' => $subjectName, 'semester' => $term['label'], 'siswa' => $students],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Buka kembali lembar FINAL (pengelola kelas saja; dicek pemanggil). Alasan wajib, isinya tidak
     * masuk audit.
     *
     * @throws GradingException
     */
    public function reopen(string $tenantId, string $classSubjectId, string $termId, ?string $reason): void
    {
        $reason = trim((string) $reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw GradingException::make('reason', 'Tulis alasan membuka kembali nilai ini (maks. '.self::REASON_MAX_LENGTH.' karakter).');
        }

        DB::transaction(function () use ($tenantId, $classSubjectId, $termId): void {
            [$row, $class, $term, $subjectName] = $this->lockEditable($tenantId, $classSubjectId, $termId);
            $sheet = $this->findSheet($row->id, $term['value'], lock: true);

            if ($sheet === null || ! $sheet->isFinal()) {
                throw GradingException::make('form', 'Nilai ini belum difinalkan.');
            }

            $sheet->status = GradeSheet::DRAFT;
            $sheet->finalized_by_membership_id = null;
            $sheet->finalized_at = null;
            $sheet->save();

            $this->audit->record(
                AcademicAuditAction::GRADE_SHEET_REOPENED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'mapel' => $subjectName, 'semester' => $term['label'], 'alasan_diisi' => true],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Ganti salinan aturan lembar DRAFT dengan aturan yang berlaku sekarang. Nilai komponen yang
     * kodenya sama dipertahankan; nilai akhir dihitung ulang.
     *
     * @return int jumlah siswa yang nilainya dihitung ulang
     *
     * @throws GradingException
     */
    public function applyLatestRules(string $tenantId, string $classSubjectId, string $termId): int
    {
        return DB::transaction(function () use ($tenantId, $classSubjectId, $termId): int {
            [$row, $class, $term, $subjectName] = $this->lockEditable($tenantId, $classSubjectId, $termId);
            $sheet = $this->findSheet($row->id, $term['value'], lock: true);

            if ($sheet === null || $sheet->isFinal()) {
                throw GradingException::make('form', 'Aturan hanya bisa diperbarui pada nilai yang sudah disimpan dan belum difinalkan.');
            }

            $before = GradingRules::fromValue($sheet->rules);
            $after = $this->grading->rulesFor($tenantId, $class->organization_id);

            if ($before->toArray() === $after->toArray()) {
                throw GradingException::make('form', 'Aturan nilai ini sudah sama dengan aturan terbaru.');
            }

            $sheet->rules = $after->toArray();
            $sheet->save();

            $count = 0;

            foreach ($this->savedGrades($sheet->id) as $enrollmentId => $grade) {
                $scores = [];

                foreach ($after->components as $component) {
                    $scores[$component['code']] = $grade['scores'][$component['code']] ?? null;
                }

                DB::table('ac_grades')->where('sheet_id', $sheet->id)->where('enrollment_id', $enrollmentId)->update([
                    'scores' => json_encode((object) $scores, JSON_THROW_ON_ERROR),
                    'final_score' => self::finalScore($after, $scores),
                    'updated_at' => CarbonImmutable::now(),
                ]);
                $count++;
            }

            $this->audit->record(
                AcademicAuditAction::GRADE_SHEET_RULES_APPLIED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'mapel' => $subjectName, 'semester' => $term['label'], 'sebelum' => $before->summary(), 'sesudah' => $after->summary()],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $count;
        });
    }

    /**
     * Nilai akhir = Σ nilai × bobot ÷ 100, dibulatkan setengah ke atas; null bila ada komponen kosong.
     * Dihitung dalam bilangan bulat (perseratus) agar tidak ada galat pembulatan desimal.
     *
     * @param  array<string, float|null>  $scores
     */
    public static function finalScore(GradingRules $rules, array $scores): ?int
    {
        $sum = 0;

        foreach ($rules->components as $component) {
            $score = $scores[$component['code']] ?? null;

            if ($score === null) {
                return null;
            }

            $sum += (int) round($score * 100) * $component['weight'];
        }

        return intdiv($sum + 5000, 10000);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, float|null> kode komponen => nilai
     *
     * @throws GradingException
     */
    private function validatedScores(GradingRules $rules, mixed $input, string $studentName): array
    {
        $input = is_array($input) ? $input : [];
        $scores = [];

        foreach ($rules->components as $component) {
            $raw = $input[$component['code']] ?? null;
            $text = is_int($raw) || is_float($raw) ? (string) $raw : (is_string($raw) ? str_replace(',', '.', trim($raw)) : '');

            if ($text === '') {
                $scores[$component['code']] = null;

                continue;
            }

            if (preg_match(self::SCORE_PATTERN, $text) !== 1 || (float) $text > 100) {
                throw GradingException::make('grades', "{$component['name']} untuk {$studentName} harus angka 0–100 (paling banyak 2 angka di belakang koma).");
            }

            $scores[$component['code']] = (float) $text;
        }

        return $scores;
    }

    /**
     * @throws GradingException
     */
    private function validatedDescription(mixed $input, string $studentName): ?string
    {
        $text = is_string($input) ? trim((string) preg_replace('/\s+/u', ' ', $input)) : '';

        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            throw GradingException::make('grades', "Deskripsi untuk {$studentName} maksimal ".self::DESCRIPTION_MAX_LENGTH.' karakter.');
        }

        return $text === '' ? null : $text;
    }

    /**
     * Kunci mapel kelas (antrean simpan per mapel kelas) dan periksa kelas boleh diubah.
     *
     * @return array{0: ClassSubject, 1: SchoolClass, 2: TermOption, 3: string}
     *
     * @throws GradingException
     */
    private function lockEditable(string $tenantId, string $classSubjectId, string $termId): array
    {
        $row = Str::isUuid($classSubjectId)
            ? ClassSubject::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classSubjectId)->lockForUpdate()->first()
            : null;

        if (! $row instanceof ClassSubject) {
            throw GradingException::make('form', 'Mata pelajaran ini sudah tidak ada di kelas tersebut. Muat ulang halaman.');
        }

        $class = SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($row->class_id)->firstOrFail();
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        if (ManageClasses::isPastYear($year)) {
            throw GradingException::make('form', 'Tahun ajaran kelas ini sudah lewat; nilainya hanya bisa dilihat.');
        }

        $term = null;

        foreach ($this->terms($class) as $option) {
            if ($option['value'] === $termId) {
                $term = $option;
            }
        }

        if ($term === null) {
            throw GradingException::make('form', 'Semester tidak ditemukan di tahun ajaran kelas ini.');
        }

        $subjectName = (string) DB::table('ac_subjects')->where('id', $row->subject_id)->value('name');

        return [$row, $class, $term, $subjectName];
    }

    private function findSheet(string $classSubjectId, string $termId, bool $lock = false): ?GradeSheet
    {
        $query = GradeSheet::query()->withoutGlobalScope('tenant')->where('class_subject_id', $classSubjectId)->where('term_id', $termId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return array<string, array{scores: array<string, float|null>, final: int|null, description: string|null}>
     */
    private function savedGrades(string $sheetId): array
    {
        $saved = [];

        foreach (DB::table('ac_grades')->where('sheet_id', $sheetId)->get(['enrollment_id', 'scores', 'final_score', 'description']) as $grade) {
            $decoded = json_decode((string) $grade->scores, true);
            $scores = [];

            foreach (is_array($decoded) ? $decoded : [] as $code => $value) {
                $scores[(string) $code] = is_int($value) || is_float($value) ? (float) $value : null;
            }

            $saved[(string) $grade->enrollment_id] = [
                'scores' => $scores,
                'final' => $grade->final_score === null ? null : (int) $grade->final_score,
                'description' => $grade->description === null ? null : (string) $grade->description,
            ];
        }

        return $saved;
    }

    /**
     * Siswa yang pernah tercatat di kelas selama semester, ditambah yang sudah punya nilai di lembar
     * ini; urut nama. `left` = keluar dari kelas sebelum semester berakhir (tidak wajib dinilai).
     *
     * @param  TermOption  $term
     * @return list<array{enrollment_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, left: bool}>
     */
    private function roster(string $classId, array $term, ?string $sheetId): array
    {
        $rows = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $classId)
            ->where(function (Builder $query) use ($term, $sheetId): void {
                $query->where(function (Builder $during) use ($term): void {
                    $during->where('cm.start_date', '<=', $term['end'])
                        ->where(function (Builder $open) use ($term): void {
                            $open->whereNull('cm.end_date')->orWhere('cm.end_date', '>', $term['start']);
                        });
                });

                if ($sheetId !== null) {
                    $query->orWhereExists(function (Builder $graded) use ($sheetId): void {
                        $graded->select(DB::raw(1))->from('ac_grades as g')->where('g.sheet_id', $sheetId)->whereColumn('g.enrollment_id', 'e.id');
                    });
                }
            })
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.student_id', 'e.student_number', 's.nisn', 'p.name', 'p.gender', 'cm.end_date']);

        $students = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            // Keluar sebelum semester berakhir; keanggotaan yang masih terbuka menang.
            $left = $row->end_date !== null && substr((string) $row->end_date, 0, 10) <= $term['end'];

            if (isset($students[$id])) {
                $students[$id]['left'] = $students[$id]['left'] && $left;

                continue;
            }

            $students[$id] = [
                'enrollment_id' => $id,
                'student_id' => (string) $row->student_id,
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'left' => $left,
            ];
        }

        return array_values($students);
    }

    private function memberName(string $membershipId): ?string
    {
        $name = DB::table('memberships as m')->join('persons as p', 'p.id', '=', 'm.person_id')->where('m.id', $membershipId)->value('p.name');

        return $name === null ? null : (string) $name;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guard(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            throw GradingException::make('form', 'Nilai ini baru saja disimpan pengguna lain. Muat ulang halaman lalu coba lagi.');
        }
    }
}
