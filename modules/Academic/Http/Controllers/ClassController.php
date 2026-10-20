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
use Modules\Academic\Application\Classroom\ClassData;
use Modules\Academic\Application\Classroom\ClassDirectory;
use Modules\Academic\Application\Classroom\ManageClasses;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Halaman Kelas (PRD-002 §6, AC-1d-a): daftar kelas per lembaga & tahun ajaran, tambah, ubah,
 * nonaktifkan/aktifkan kembali, hapus kelas kosong.
 *
 * Melihat: `academic.students.view` di lembaga itu (§5.1). Mengelola: `academic.classes.manage`
 * di lembaga kelas (Gate dengan datanya: di luar cakupan 404, tanpa izin 403).
 */
final class ClassController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, ClassDirectory $directory, ActiveEmployees $employees): Response
    {
        Gate::authorize(AcademicAccess::STUDENTS_VIEW);

        $tenantId = $this->tenantId();
        $organizations = $this->organizationOptions();
        $organizationId = $this->pick($request->query('lembaga'), array_column($organizations, 'value'));

        $years = $organizationId === null ? [] : $directory->yearOptions($tenantId, $organizationId);
        $yearId = $this->pick($request->query('tahun'), array_column($years, 'value'), $this->defaultYear($years));
        $year = null;

        foreach ($years as $option) {
            if ($option['value'] === $yearId) {
                $year = $option;
            }
        }

        $canManage = false;
        $homerooms = [];

        if ($organizationId !== null && $year !== null && ! $year['past'] && $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $organizationId)) {
            $canManage = true;
            // Pilihan wali kelas hanya untuk pengelola (nama pegawai aktif di lembaga itu).
            $homerooms = $employees->inOrganization($tenantId, $organizationId);
        }

        return Inertia::render('academic/classes/index', [
            'filters' => ['lembaga' => $organizationId, 'tahun' => $yearId],
            'options' => ['organizations' => $organizations, 'years' => $years],
            'year' => $year,
            'classes' => $organizationId !== null && $yearId !== null ? $directory->list($tenantId, $organizationId, $yearId) : [],
            'canManage' => $canManage,
            'homerooms' => $homerooms,
        ]);
    }

    public function store(Request $request, ManageClasses $classes): RedirectResponse
    {
        $request->validate(array_merge($this->rules(), [
            'organization_id' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'uuid'],
        ]), $this->messages(), $this->attributes());

        $organization = Organization::query()->find($request->string('organization_id')->value());

        if (! $organization instanceof Organization) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $organization);

        $class = $this->attempt(fn (): SchoolClass => $classes->create(
            $this->tenantId(),
            $organization->id,
            $request->string('academic_year_id')->value(),
            $this->data($request),
        ));

        return $this->done("Kelas {$class->name} ditambahkan.");
    }

    public function update(Request $request, string $class, ManageClasses $classes): RedirectResponse
    {
        $target = $this->findAndAuthorize($class);

        $request->validate($this->rules(), $this->messages(), $this->attributes());

        $changed = $this->attempt(fn (): array => $classes->update($this->tenantId(), $target->id, $this->data($request)));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Kelas disimpan.');
    }

    public function status(Request $request, string $class, ManageClasses $classes): RedirectResponse
    {
        $target = $this->findAndAuthorize($class);

        $request->validate(['active' => ['required', 'boolean']]);

        $updated = $this->attempt(fn (): SchoolClass => $classes->setActive($this->tenantId(), $target->id, $request->boolean('active')));

        return $this->done($updated->is_active
            ? "Kelas {$updated->name} diaktifkan kembali."
            : "Kelas {$updated->name} dinonaktifkan. Siswa baru tidak bisa dimasukkan ke kelas ini.");
    }

    public function destroy(string $class, ManageClasses $classes): RedirectResponse
    {
        $target = $this->findAndAuthorize($class);

        $name = $this->attempt(fn (): string => $classes->delete($this->tenantId(), $target->id));

        return $this->done("Kelas {$name} dihapus.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function findAndAuthorize(string $classId): SchoolClass
    {
        $class = Str::isUuid($classId) ? SchoolClass::query()->find($classId) : null;

        if (! $class instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $class);

        return $class;
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

    /**
     * Nilai dari URL hanya dipakai bila termasuk pilihan yang sah; selain itu bawaan.
     *
     * @param  list<string>  $allowed
     */
    private function pick(mixed $requested, array $allowed, ?string $default = null): ?string
    {
        if (is_string($requested) && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $default ?? ($allowed[0] ?? null);
    }

    /**
     * @param  list<array{value: string, label: string, current: bool, past: bool}>  $years
     */
    private function defaultYear(array $years): ?string
    {
        foreach ($years as $year) {
            if ($year['current']) {
                return $year['value'];
            }
        }

        return null;
    }

    private function data(Request $request): ClassData
    {
        $homeroom = $request->input('homeroom_employee_id');

        return new ClassData(
            grade: $request->integer('grade'),
            name: ClassData::cleanName($request->input('name')),
            homeroomEmployeeId: is_string($homeroom) && $homeroom !== '' ? $homeroom : null,
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
        } catch (ClassException $exception) {
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
            'grade' => ['required', 'integer', 'between:1,12'],
            'name' => ['required', 'string', 'max:'.ManageClasses::NAME_MAX_LENGTH],
            'homeroom_employee_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'grade.required' => 'Pilih tingkat.',
            'grade.between' => 'Tingkat harus angka 1–12.',
            'name.required' => 'Nama kelas wajib diisi.',
            'organization_id.required' => 'Pilih lembaga.',
            'academic_year_id.required' => 'Pilih tahun ajaran.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'grade' => 'tingkat',
            'name' => 'nama kelas',
            'homeroom_employee_id' => 'wali kelas',
            'organization_id' => 'lembaga',
            'academic_year_id' => 'tahun ajaran',
        ];
    }
}
