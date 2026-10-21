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
use Modules\Academic\Application\Classroom\ManageClassMembers;
use Modules\Academic\Application\Classroom\ManageClassSubjects;
use Modules\Academic\Domain\Classroom\ClassMember;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Detail kelas & anggota kelas (PRD-002 §6, AC-1d-b): lihat anggota, masukkan siswa (banyak
 * sekaligus), pindah kelas, keluarkan dari kelas.
 *
 * Melihat: `academic.students.view` di lembaga kelas. Mengelola: `academic.classes.manage` di
 * lembaga kelas. Gate dengan datanya: di luar cakupan 404, tanpa izin 403.
 */
final class ClassMemberController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(string $class, ClassDirectory $directory, ManageClassMembers $members, ManageClassSubjects $subjects, ActiveEmployees $employees): Response
    {
        $target = $this->findClass($class);
        Gate::authorize(AcademicAccess::STUDENTS_VIEW, $target);

        $detail = $directory->detail($target);
        $canManage = ! $detail['year']['past'] && $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $target->organization_id);

        return Inertia::render('academic/classes/show', $detail + [
            'canManage' => $canManage,
            // Proses akhir tahun (AC-2) tetap bisa untuk tahun yang sudah lewat: kelas ini hanya dibaca.
            'canYearEnd' => $this->authorization->allows(AcademicAccess::CLASSES_MANAGE, $target->organization_id),
            // Calon anggota hanya untuk pengelola dan hanya bila kelas aktif.
            'candidates' => $canManage && $target->is_active ? $members->candidates($target) : [],
            // Mapel yang bisa ditambahkan & pilihan pengampu (AC-3b), hanya untuk pengelola.
            'subjectOptions' => $canManage ? $subjects->available($target) : [],
            'teachers' => $canManage ? $employees->inOrganization($target->tenant_id, $target->organization_id) : [],
            'today' => BusinessClock::todayString(),
            'maxPerSave' => ManageClassMembers::MAX_PER_SAVE,
        ]);
    }

    public function add(Request $request, string $class, ManageClassMembers $members): RedirectResponse
    {
        $target = $this->findClass($class);
        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $target);

        $request->validate([
            'enrollment_ids' => ['required', 'array', 'max:'.ManageClassMembers::MAX_PER_SAVE],
            'enrollment_ids.*' => ['required', 'uuid'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'enrollment_ids.required' => 'Pilih minimal satu siswa.',
            'enrollment_ids.max' => 'Paling banyak '.ManageClassMembers::MAX_PER_SAVE.' siswa sekali simpan.',
        ], ['enrollment_ids' => 'siswa', 'start_date' => 'tanggal masuk kelas']);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', (array) $request->input('enrollment_ids', [])));

        $count = $this->attempt(fn (): int => $members->add($this->tenantId(), $target->id, $ids, $this->date($request, 'start_date')));

        return $this->done("{$count} siswa dimasukkan ke kelas {$target->name}.");
    }

    public function move(Request $request, string $member, ManageClassMembers $members): RedirectResponse
    {
        $target = $this->findMember($member);

        $request->validate([
            'target_class_id' => ['required', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], ['target_class_id.required' => 'Pilih kelas tujuan.'], ['target_class_id' => 'kelas tujuan', 'date' => 'tanggal pindah']);

        // Kelas tujuan juga wajib dalam cakupan kelola (lembaga yang sama dicek di service).
        $destination = $this->findClass($request->string('target_class_id')->value());
        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $destination);

        $class = $this->attempt(fn (): SchoolClass => $members->move($this->tenantId(), $target->id, $destination->id, $this->date($request, 'date')));

        return $this->done("Siswa dipindah ke kelas {$class->name}.");
    }

    public function remove(Request $request, string $member, ManageClassMembers $members): RedirectResponse
    {
        $target = $this->findMember($member);

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']], [], ['date' => 'tanggal keluar']);

        $class = $this->attempt(fn (): SchoolClass => $members->remove($this->tenantId(), $target->id, $this->date($request, 'date')));

        return $this->done("Siswa dikeluarkan dari kelas {$class->name}. Pendaftarannya di lembaga tetap aktif.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function findClass(string $classId): SchoolClass
    {
        $class = Str::isUuid($classId) ? SchoolClass::query()->find($classId) : null;

        if (! $class instanceof SchoolClass) {
            abort(404);
        }

        return $class;
    }

    private function findMember(string $memberId): ClassMember
    {
        $member = Str::isUuid($memberId) ? ClassMember::query()->find($memberId) : null;

        if (! $member instanceof ClassMember) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $member);

        return $member;
    }

    private function date(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
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
        } catch (ClassException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
