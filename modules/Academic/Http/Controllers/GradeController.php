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
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Lembar nilai mapel kelas per semester (PRD-002 §19 AC-5b). Dua pintu, aturan sama:
 * - `/kelas/mapel/{id}/nilai`: dilihat pemilik `academic.students.view` di lembaga kelas; diisi,
 *   difinalkan, dibuka kembali, dan diperbarui aturannya oleh `academic.classes.manage`.
 * - `/kelas-saya/mapel/{id}/nilai`: guru pengampu mapel itu (Kelas saya) mengisi & memfinalkan;
 *   bukan mapel yang ia ampu → 404.
 * Gate dengan datanya: di luar cakupan 404, tanpa izin 403.
 */
final class GradeController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    // ── Pintu operator / pemantau ────────────────────────────────────────────

    public function show(Request $request, string $classSubject, ManageGrades $grades): Response
    {
        $row = $this->findRow($classSubject);
        Gate::authorize(AcademicAccess::STUDENTS_VIEW, $row);

        $canManage = Gate::allows(AcademicAccess::CLASSES_MANAGE, $row);
        $class = $this->classOf($row);

        return $this->page($request, $row, $grades, $canManage, $canManage, "/kelas/mapel/{$row->id}/nilai", "/kelas/{$class->id}", 'Detail kelas');
    }

    public function store(Request $request, string $classSubject, ManageGrades $grades): RedirectResponse
    {
        $row = $this->managedRow($classSubject);

        return $this->save($request, $row, $grades, "/kelas/mapel/{$row->id}/nilai");
    }

    public function finalize(Request $request, string $classSubject, ManageGrades $grades): RedirectResponse
    {
        $row = $this->managedRow($classSubject);

        return $this->doFinalize($request, $row, $grades, "/kelas/mapel/{$row->id}/nilai");
    }

    public function reopen(Request $request, string $classSubject, ManageGrades $grades): RedirectResponse
    {
        $row = $this->managedRow($classSubject);
        $request->validate(['reason' => ['nullable', 'string', 'max:'.ManageGrades::REASON_MAX_LENGTH]], [], ['reason' => 'alasan']);
        $termId = $this->termId($request);
        $reason = $request->input('reason');

        $this->run(function () use ($grades, $row, $termId, $reason): void {
            $grades->reopen($this->tenantId(), $row->id, $termId, is_string($reason) ? $reason : null);
        });

        return $this->done('Nilai dibuka kembali dan bisa diubah lagi.', "/kelas/mapel/{$row->id}/nilai", $termId);
    }

    public function applyRules(Request $request, string $classSubject, ManageGrades $grades): RedirectResponse
    {
        $row = $this->managedRow($classSubject);
        $termId = $this->termId($request);

        $count = $this->attempt(fn (): int => $grades->applyLatestRules($this->tenantId(), $row->id, $termId));

        return $this->done("Aturan nilai diperbarui; nilai akhir {$count} siswa dihitung ulang.", "/kelas/mapel/{$row->id}/nilai", $termId);
    }

    // ── Pintu guru pengampu (Kelas saya) ─────────────────────────────────────

    public function showMine(Request $request, string $classSubject, MyClasses $myClasses, ManageGrades $grades): Response
    {
        $row = $this->myRow($classSubject, $myClasses);

        return $this->page($request, $row, $grades, true, false, "/kelas-saya/mapel/{$row->id}/nilai", "/kelas-saya/{$row->class_id}", 'Kelas saya');
    }

    public function storeMine(Request $request, string $classSubject, MyClasses $myClasses, ManageGrades $grades): RedirectResponse
    {
        $row = $this->myRow($classSubject, $myClasses);

        return $this->save($request, $row, $grades, "/kelas-saya/mapel/{$row->id}/nilai");
    }

    public function finalizeMine(Request $request, string $classSubject, MyClasses $myClasses, ManageGrades $grades): RedirectResponse
    {
        $row = $this->myRow($classSubject, $myClasses);

        return $this->doFinalize($request, $row, $grades, "/kelas-saya/mapel/{$row->id}/nilai");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @param  bool  $canEdit  boleh mengisi & memfinalkan (bila tahun belum lewat dan lembar belum final)
     * @param  bool  $canManage  boleh membuka kembali & memperbarui aturan (pengelola kelas)
     */
    private function page(Request $request, ClassSubject $row, ManageGrades $grades, bool $canEdit, bool $canManage, string $baseUrl, string $backUrl, string $backLabel): Response
    {
        $class = $this->classOf($row);
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $past = ManageClasses::isPastYear($year);
        $terms = $grades->terms($class);
        $term = ManageGrades::pickTerm($terms, $request->query('semester'));
        $teacherId = $row->teacher_employee_id;

        return Inertia::render('academic/grades/sheet', [
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'organization' => (string) DB::table('organizations')->where('id', $class->organization_id)->value('name'),
                'year' => $year->name,
            ],
            'subject' => [
                'id' => $row->id,
                'name' => (string) DB::table('ac_subjects')->where('id', $row->subject_id)->value('name'),
                'teacher' => is_string($teacherId) ? (app(ActiveEmployees::class)->describe($row->tenant_id, [$teacherId])[$teacherId]['name'] ?? null) : null,
            ],
            'terms' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $terms),
            'term' => $term,
            'sheet' => $term === null ? null : $grades->sheet($row, $term),
            'past' => $past,
            'canEdit' => $canEdit && ! $past,
            'canManage' => $canManage && ! $past,
            'limits' => ['description' => ManageGrades::DESCRIPTION_MAX_LENGTH, 'reason' => ManageGrades::REASON_MAX_LENGTH],
            'baseUrl' => $baseUrl,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    private function save(Request $request, ClassSubject $row, ManageGrades $grades, string $redirectTo): RedirectResponse
    {
        $request->validate([
            'grades' => ['required', 'array', 'max:'.ManageGrades::MAX_STUDENTS],
            'grades.*' => ['array'],
            'grades.*.scores' => ['nullable', 'array'],
            'grades.*.description' => ['nullable', 'string', 'max:'.ManageGrades::DESCRIPTION_MAX_LENGTH],
        ], [
            'grades.required' => 'Belum ada nilai yang diisi.',
            'grades.max' => 'Paling banyak '.ManageGrades::MAX_STUDENTS.' siswa sekali simpan.',
            'grades.*.description.max' => 'Deskripsi maksimal '.ManageGrades::DESCRIPTION_MAX_LENGTH.' karakter.',
        ]);

        $termId = $this->termId($request);

        /** @var array<string, array{scores: mixed, description: mixed}> $entries */
        $entries = [];

        foreach ((array) $request->input('grades', []) as $enrollmentId => $entry) {
            $entry = (array) $entry;
            $entries[(string) $enrollmentId] = ['scores' => $entry['scores'] ?? [], 'description' => $entry['description'] ?? null];
        }

        $result = $this->attempt(fn (): array => $grades->save($this->tenantId(), $row->id, $termId, $entries));

        return $this->done("Nilai disimpan untuk {$result['saved']} siswa; {$result['complete']} siswa sudah punya nilai akhir.", $redirectTo, $termId);
    }

    private function doFinalize(Request $request, ClassSubject $row, ManageGrades $grades, string $redirectTo): RedirectResponse
    {
        $termId = $this->termId($request);

        $this->run(function () use ($grades, $row, $termId): void {
            $grades->finalize($this->tenantId(), $row->id, $termId, $this->tenantContext->require()->membershipId);
        });

        return $this->done('Nilai difinalkan. Nilai ini sekarang dipakai di rapor dan hanya bisa diubah setelah dibuka kembali oleh operator.', $redirectTo, $termId);
    }

    private function termId(Request $request): string
    {
        $request->validate(['semester' => ['required', 'uuid']], ['semester.required' => 'Pilih semester.'], ['semester' => 'semester']);

        return $request->string('semester')->value();
    }

    private function findRow(string $classSubjectId): ClassSubject
    {
        $row = Str::isUuid($classSubjectId) ? ClassSubject::query()->find($classSubjectId) : null;

        if (! $row instanceof ClassSubject) {
            abort(404);
        }

        return $row;
    }

    private function managedRow(string $classSubjectId): ClassSubject
    {
        $row = $this->findRow($classSubjectId);
        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $row);

        return $row;
    }

    /**
     * Mapel kelas yang diampu pengguna ini di kelas Kelas saya-nya; selain itu 404.
     */
    private function myRow(string $classSubjectId, MyClasses $myClasses): ClassSubject
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $row = Str::isUuid($classSubjectId)
            ? ClassSubject::query()->withoutGlobalScope('tenant')->where('tenant_id', $context->tenantId)->whereKey($classSubjectId)->first()
            : null;

        if ($employeeId === null || ! $row instanceof ClassSubject || $row->teacher_employee_id !== $employeeId
            || MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $row->class_id) === null) {
            abort(404);
        }

        return $row;
    }

    private function classOf(ClassSubject $row): SchoolClass
    {
        return SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($row->class_id)->firstOrFail();
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (GradingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    /**
     * @param  callable(): void  $action
     */
    private function run(callable $action): void
    {
        try {
            $action();
        } catch (GradingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message, string $redirectTo, string $termId): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->to($redirectTo.'?semester='.$termId);
    }
}
