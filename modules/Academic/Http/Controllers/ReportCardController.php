<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Classroom\ManageClasses;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Application\Grading\ManageGrades;
use Modules\Academic\Application\Grading\ReportCards;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;

/**
 * Rapor (PRD-002 §19 AC-5d): daftar rapor kelas per semester + catatan wali kelas, dan halaman cetak
 * (satu siswa atau sekelas). Dua pintu:
 * - `/kelas/{class}/rapor`: dilihat pemilik `academic.students.view` di lembaga kelas; catatan diisi
 *   `academic.classes.manage` (di luar cakupan 404, tanpa izin 403).
 * - `/kelas-saya/{class}/rapor`: wali kelas itu (Kelas saya) melihat, mengisi catatan, dan mencetak;
 *   bukan wali kelasnya → 404.
 */
final class ReportCardController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, string $class, ManageGrades $grades, ReportCards $reports): Response
    {
        $target = $this->viewableClass($class);

        return $this->page($request, $target, $grades, $reports, Gate::allows(AcademicAccess::CLASSES_MANAGE, $target), "/kelas/{$target->id}/rapor", "/kelas/{$target->id}", 'Detail kelas');
    }

    public function saveNotes(Request $request, string $class, ReportCards $reports): RedirectResponse
    {
        $target = $this->viewableClass($class);
        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $target);

        return $this->save($request, $target, $reports, "/kelas/{$target->id}/rapor");
    }

    public function print(Request $request, string $class, ManageGrades $grades, ReportCards $reports): Response
    {
        return $this->printPage($request, $this->viewableClass($class), $grades, $reports, "/kelas/{$class}/rapor");
    }

    public function indexMine(Request $request, string $class, MyClasses $myClasses, ManageGrades $grades, ReportCards $reports): Response
    {
        $target = $this->homeroomClass($class, $myClasses);

        return $this->page($request, $target, $grades, $reports, true, "/kelas-saya/{$target->id}/rapor", "/kelas-saya/{$target->id}", "Kelas {$target->name}");
    }

    public function saveNotesMine(Request $request, string $class, MyClasses $myClasses, ReportCards $reports): RedirectResponse
    {
        $target = $this->homeroomClass($class, $myClasses);

        return $this->save($request, $target, $reports, "/kelas-saya/{$target->id}/rapor");
    }

    public function printMine(Request $request, string $class, MyClasses $myClasses, ManageGrades $grades, ReportCards $reports): Response
    {
        return $this->printPage($request, $this->homeroomClass($class, $myClasses), $grades, $reports, "/kelas-saya/{$class}/rapor");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function page(Request $request, SchoolClass $class, ManageGrades $grades, ReportCards $reports, bool $canEditNotes, string $baseUrl, string $backUrl, string $backLabel): Response
    {
        $year = $this->yearOf($class);
        $terms = $grades->terms($class);
        $term = ManageGrades::pickTerm($terms, $request->query('semester'));
        $report = $term === null ? null : $reports->build($class, $term);
        $students = [];

        foreach ($report['cards'] ?? [] as $card) {
            $students[] = [
                'enrollment_id' => $card['enrollment_id'],
                'name' => $card['name'],
                'nisn' => $card['nisn'],
                'left' => $card['left'],
                'graded' => count(array_filter($card['subjects'], fn (array $line): bool => $line['final'] !== null)),
                'average' => $card['average'],
                'attendance' => $card['attendance'],
                'note' => $card['note'],
            ];
        }

        return Inertia::render('academic/grades/report-index', [
            'class' => $this->classInfo($class, $year),
            'terms' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $terms),
            'term' => $term,
            'students' => $students,
            'pending' => $report['pending'] ?? [],
            'finalSubjects' => $report['final_subjects'] ?? 0,
            'canEditNotes' => $canEditNotes && ! ManageClasses::isPastYear($year),
            'noteMax' => ReportCards::NOTE_MAX_LENGTH,
            'baseUrl' => $baseUrl,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    private function printPage(Request $request, SchoolClass $class, ManageGrades $grades, ReportCards $reports, string $backUrl): Response
    {
        $year = $this->yearOf($class);
        $term = ManageGrades::pickTerm($grades->terms($class), $request->query('semester'));

        if ($term === null) {
            abort(404);
        }

        $student = $request->query('siswa');
        $report = $reports->build($class, $term, is_string($student) && Str::isUuid($student) ? $student : null);

        if (is_string($student) && $report['cards'] === []) {
            abort(404);
        }

        return Inertia::render('print/report-cards', [
            'class' => $this->classInfo($class, $year) + ['grade' => $class->grade],
            'term' => $term,
            'report' => $report,
            'today' => BusinessClock::todayString(),
            'backUrl' => $backUrl.'?semester='.$term['value'],
        ]);
    }

    private function save(Request $request, SchoolClass $class, ReportCards $reports, string $redirectTo): RedirectResponse
    {
        $request->validate([
            'semester' => ['required', 'uuid'],
            'notes' => ['required', 'array', 'max:200'],
            'notes.*' => ['nullable', 'string', 'max:'.ReportCards::NOTE_MAX_LENGTH],
        ], [
            'semester.required' => 'Pilih semester.',
            'notes.required' => 'Belum ada catatan yang diisi.',
            'notes.*.max' => 'Catatan maksimal '.ReportCards::NOTE_MAX_LENGTH.' karakter.',
        ]);

        $termId = $request->string('semester')->value();

        /** @var array<string, mixed> $entries */
        $entries = [];

        foreach ((array) $request->input('notes', []) as $enrollmentId => $note) {
            $entries[(string) $enrollmentId] = $note;
        }

        try {
            $changed = $reports->saveNotes($this->tenantContext->require()->tenantId, $class->id, $termId, $entries, $this->tenantContext->require()->membershipId);
        } catch (GradingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed > 0 ? "Catatan wali kelas disimpan ({$changed} siswa)." : 'Tidak ada catatan yang berubah.']);

        return redirect()->to($redirectTo.'?semester='.$termId);
    }

    /**
     * @return array{id: string, name: string, organization: string, year: string}
     */
    private function classInfo(SchoolClass $class, AcademicYear $year): array
    {
        return [
            'id' => $class->id,
            'name' => $class->name,
            'organization' => (string) DB::table('organizations')->where('id', $class->organization_id)->value('name'),
            'year' => $year->name,
        ];
    }

    private function yearOf(SchoolClass $class): AcademicYear
    {
        return AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
    }

    private function viewableClass(string $classId): SchoolClass
    {
        $class = Str::isUuid($classId) ? SchoolClass::query()->find($classId) : null;

        if (! $class instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::STUDENTS_VIEW, $class);

        return $class;
    }

    private function homeroomClass(string $classId, MyClasses $myClasses): SchoolClass
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $picked = $employeeId === null ? null : MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $classId);

        if ($picked === null || ! $picked['homeroom']) {
            abort(404);
        }

        return SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($picked['id'])->firstOrFail();
    }
}
