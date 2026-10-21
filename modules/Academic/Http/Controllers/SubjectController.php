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
use Modules\Academic\Application\Subject\ManageSubjects;
use Modules\Academic\Application\Subject\SubjectData;
use Modules\Academic\Application\Subject\SubjectDirectory;
use Modules\Academic\Domain\Subject\Exceptions\SubjectException;
use Modules\Academic\Domain\Subject\Subject;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Halaman Mata pelajaran (PRD-002 §16 AC-3a): daftar per lembaga, tambah, ubah,
 * nonaktifkan/aktifkan kembali, hapus.
 *
 * Melihat: `academic.students.view` di lembaga itu. Mengelola: `academic.classes.manage` di lembaga
 * mapel (Gate dengan datanya: di luar cakupan 404, tanpa izin 403).
 */
final class SubjectController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, SubjectDirectory $directory): Response
    {
        Gate::authorize(AcademicAccess::STUDENTS_VIEW);

        $organizations = $this->organizationOptions();
        $allowed = array_column($organizations, 'value');
        $requested = $request->query('lembaga');
        $organizationId = is_string($requested) && in_array($requested, $allowed, true) ? $requested : ($allowed[0] ?? null);

        return Inertia::render('academic/subjects/index', [
            'filters' => ['lembaga' => $organizationId],
            'options' => ['organizations' => $organizations],
            'subjects' => $organizationId === null ? [] : $directory->list($this->tenantId(), $organizationId),
            'canManage' => $organizationId !== null && $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $organizationId),
        ]);
    }

    public function store(Request $request, ManageSubjects $subjects): RedirectResponse
    {
        $request->validate(array_merge($this->rules(), [
            'organization_id' => ['required', 'uuid'],
        ]), $this->messages(), $this->attributes());

        $organization = Organization::query()->find($request->string('organization_id')->value());

        if (! $organization instanceof Organization) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $organization);

        $subject = $this->attempt(fn (): Subject => $subjects->create($this->tenantId(), $organization->id, $this->data($request)));

        return $this->done("Mata pelajaran {$subject->name} ditambahkan.");
    }

    public function update(Request $request, string $subject, ManageSubjects $subjects): RedirectResponse
    {
        $target = $this->findAndAuthorize($subject);

        $request->validate($this->rules(), $this->messages(), $this->attributes());

        $changed = $this->attempt(fn (): array => $subjects->update($this->tenantId(), $target->id, $this->data($request)));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Mata pelajaran disimpan.');
    }

    public function status(Request $request, string $subject, ManageSubjects $subjects): RedirectResponse
    {
        $target = $this->findAndAuthorize($subject);

        $request->validate(['active' => ['required', 'boolean']]);

        $updated = $this->attempt(fn (): Subject => $subjects->setActive($this->tenantId(), $target->id, $request->boolean('active')));

        return $this->done($updated->is_active
            ? "Mata pelajaran {$updated->name} diaktifkan kembali."
            : "Mata pelajaran {$updated->name} dinonaktifkan. Mapel ini tidak bisa dipilih lagi untuk kelas.");
    }

    public function destroy(string $subject, ManageSubjects $subjects): RedirectResponse
    {
        $target = $this->findAndAuthorize($subject);

        $name = $this->attempt(fn (): string => $subjects->delete($this->tenantId(), $target->id));

        return $this->done("Mata pelajaran {$name} dihapus.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function findAndAuthorize(string $subjectId): Subject
    {
        $subject = Str::isUuid($subjectId) ? Subject::query()->find($subjectId) : null;

        if (! $subject instanceof Subject) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $subject);

        return $subject;
    }

    /**
     * Lembaga aktif yang terlihat (izin lihat siswa) di lembaga kerja ini.
     *
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(): array
    {
        $organizations = Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(AcademicAccess::STUDENTS_VIEW))
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        $options = [];

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    private function data(Request $request): SubjectData
    {
        return new SubjectData(
            name: SubjectData::cleanName($request->input('name')),
            code: SubjectData::cleanCode($request->input('code')),
            gradeMin: SubjectData::cleanGrade($request->input('grade_min')),
            gradeMax: SubjectData::cleanGrade($request->input('grade_max')),
        );
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * Jalankan service; pelanggaran aturan tampil sebagai pesan di isian form terkait.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (SubjectException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ManageSubjects::NAME_MAX_LENGTH],
            'code' => ['nullable', 'string', 'max:'.ManageSubjects::CODE_MAX_LENGTH],
            'grade_min' => ['nullable', 'integer', 'between:1,12'],
            'grade_max' => ['nullable', 'integer', 'between:1,12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Nama mata pelajaran wajib diisi.',
            'grade_min.between' => 'Tingkat harus angka 1–12.',
            'grade_max.between' => 'Tingkat harus angka 1–12.',
            'organization_id.required' => 'Pilih lembaga.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'name' => 'nama mata pelajaran',
            'code' => 'kode',
            'grade_min' => 'tingkat dari',
            'grade_max' => 'tingkat sampai',
            'organization_id' => 'lembaga',
        ];
    }
}
