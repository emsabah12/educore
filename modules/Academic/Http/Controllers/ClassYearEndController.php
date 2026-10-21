<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Classroom\ClassDirectory;
use Modules\Academic\Application\Classroom\YearEndProcess;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;

/**
 * Proses akhir tahun per kelas (PRD-002 §15, AC-2): naik kelas, tinggal kelas, lulus.
 *
 * Siapa: `academic.classes.manage` di lembaga kelas (Gate dengan datanya: di luar cakupan 404,
 * tanpa izin 403). Mencatat lulus juga butuh `academic.students.manage` di lembaga itu, karena
 * lulus menutup pendaftaran siswa.
 */
final class ClassYearEndController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(string $class, ClassDirectory $directory, YearEndProcess $process): Response
    {
        $target = $this->findClass($class);
        $canGraduate = $this->canGraduate($target);
        $detail = $directory->detail($target);

        return Inertia::render('academic/classes/year-end', $process->plan($target, $canGraduate) + [
            'class' => $detail['class'],
            'canGraduate' => $canGraduate,
            'today' => BusinessClock::todayString(),
            'maxStudents' => YearEndProcess::MAX_STUDENTS,
        ]);
    }

    public function store(Request $request, string $class, YearEndProcess $process): RedirectResponse
    {
        $target = $this->findClass($class);

        $request->validate([
            'decisions' => ['required', 'array', 'max:'.YearEndProcess::MAX_STUDENTS],
            'decisions.*' => ['required', 'string', 'max:40'],
            'graduation_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'decisions.required' => 'Pilih minimal satu siswa untuk diproses (naik, tinggal kelas, atau lulus).',
            'decisions.max' => 'Paling banyak '.YearEndProcess::MAX_STUDENTS.' siswa sekali proses.',
        ], ['decisions' => 'pilihan siswa', 'graduation_date' => 'tanggal lulus']);

        /** @var array<string, string> $decisions */
        $decisions = [];

        foreach ((array) $request->input('decisions', []) as $memberId => $value) {
            $decisions[(string) $memberId] = (string) $value;
        }

        // Lulus menutup pendaftaran: hanya pengelola data siswa di lembaga itu.
        if (in_array(YearEndProcess::GRADUATE, $decisions, true) && ! $this->canGraduate($target)) {
            abort(403);
        }

        $graduationDate = $request->input('graduation_date');

        try {
            $counts = $process->process(
                $this->tenantContext->require()->tenantId,
                $target->id,
                $decisions,
                is_string($graduationDate) && $graduationDate !== '' ? $graduationDate : null,
            );
        } catch (ClassException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Proses akhir tahun kelas {$target->name} tersimpan: {$counts['promoted']} naik kelas, {$counts['retained']} tinggal kelas, {$counts['graduated']} lulus.",
        ]);

        return redirect()->route('academic.classes.show', ['class' => $target->id]);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function findClass(string $classId): SchoolClass
    {
        $class = Str::isUuid($classId) ? SchoolClass::query()->find($classId) : null;

        if (! $class instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $class);

        return $class;
    }

    private function canGraduate(SchoolClass $class): bool
    {
        return $this->authorization->allows(AcademicAccess::STUDENTS_MANAGE, $class->organization_id);
    }
}
