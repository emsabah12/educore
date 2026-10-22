<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Application\Grading\GradeLedger;
use Modules\Academic\Application\Grading\ManageGrades;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Domain\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Leger nilai kelas per semester (PRD-002 §19 AC-5c) + unduh .xlsx. Dua pintu:
 * - `/kelas/{class}/leger`: pemilik `academic.students.view` di lembaga kelas (di luar cakupan 404,
 *   tanpa izin 403); tautan lembar nilai per mapel ikut tampil.
 * - `/kelas-saya/{class}/leger`: wali kelas itu (Kelas saya); bukan wali kelasnya → 404.
 */
final class GradeLedgerController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(Request $request, string $class, ManageGrades $grades, GradeLedger $ledger): Response
    {
        $target = $this->viewableClass($class);

        return $this->page($request, $target, $grades, $ledger, "/kelas/{$target->id}/leger", "/kelas/{$target->id}", 'Detail kelas', '/kelas/mapel');
    }

    public function export(Request $request, string $class, ManageGrades $grades, GradeLedger $ledger): BinaryFileResponse
    {
        return $this->download($request, $this->viewableClass($class), $grades, $ledger);
    }

    public function showMine(Request $request, string $class, MyClasses $myClasses, ManageGrades $grades, GradeLedger $ledger): Response
    {
        $target = $this->homeroomClass($class, $myClasses);

        return $this->page($request, $target, $grades, $ledger, "/kelas-saya/{$target->id}/leger", "/kelas-saya/{$target->id}", "Kelas {$target->name}", null);
    }

    public function exportMine(Request $request, string $class, MyClasses $myClasses, ManageGrades $grades, GradeLedger $ledger): BinaryFileResponse
    {
        return $this->download($request, $this->homeroomClass($class, $myClasses), $grades, $ledger);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @param  string|null  $sheetBase  awalan tautan lembar nilai per mapel (null = tanpa tautan)
     */
    private function page(Request $request, SchoolClass $class, ManageGrades $grades, GradeLedger $ledger, string $baseUrl, string $backUrl, string $backLabel, ?string $sheetBase): Response
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $terms = $grades->terms($class);
        $term = ManageGrades::pickTerm($terms, $request->query('semester'));

        return Inertia::render('academic/grades/ledger', [
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'organization' => (string) DB::table('organizations')->where('id', $class->organization_id)->value('name'),
                'year' => $year->name,
            ],
            'terms' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $terms),
            'term' => $term,
            'ledger' => $term === null ? null : $ledger->build($class, $term),
            'sheetBase' => $sheetBase,
            'baseUrl' => $baseUrl,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    private function download(Request $request, SchoolClass $class, ManageGrades $grades, GradeLedger $ledger): BinaryFileResponse
    {
        $term = ManageGrades::pickTerm($grades->terms($class), $request->query('semester'));

        if ($term === null) {
            abort(404);
        }

        $path = tempnam(sys_get_temp_dir(), 'educore-leger-');

        if ($path === false) {
            abort(500, 'File leger tidak bisa dibuat.');
        }

        $ledger->write($ledger->build($class, $term), $path);
        $year = (string) AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->value('name');

        return response()
            ->download($path, GradeLedger::fileName($class, $term['label'], $year), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
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

    /**
     * Kelas yang ia walikan (Kelas saya); selain itu 404.
     */
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
