<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Classroom\ManageClassSubjects;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Mata pelajaran kelas & guru pengampu di detail kelas (PRD-002 §16 AC-3b): tambah mapel (banyak
 * sekaligus), salin dari kelas lain (§18 P3), ganti pengampu, lepas mapel.
 *
 * Mengelola: `academic.classes.manage` di lembaga kelas (Gate dengan datanya: di luar cakupan 404,
 * tanpa izin 403).
 */
final class ClassSubjectController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function store(Request $request, string $class, ManageClassSubjects $subjects): RedirectResponse
    {
        $target = Str::isUuid($class) ? SchoolClass::query()->find($class) : null;

        if (! $target instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $target);

        $request->validate([
            'subject_ids' => ['required', 'array', 'max:50'],
            'subject_ids.*' => ['required', 'uuid'],
        ], [
            'subject_ids.required' => 'Pilih minimal satu mata pelajaran.',
            'subject_ids.max' => 'Paling banyak 50 mata pelajaran sekali simpan.',
        ], ['subject_ids' => 'mata pelajaran']);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', (array) $request->input('subject_ids', [])));

        $count = $this->attempt(fn (): int => $subjects->add($this->tenantId(), $target->id, $ids));

        return $this->done("{$count} mata pelajaran ditambahkan ke kelas {$target->name}.");
    }

    /**
     * Salin mapel & pengampu dari kelas lain di lembaga yang sama (PRD-002 §18 P3).
     */
    public function copy(Request $request, string $class, ManageClassSubjects $subjects): RedirectResponse
    {
        $target = Str::isUuid($class) ? SchoolClass::query()->find($class) : null;

        if (! $target instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $target);

        $request->validate(
            ['source_class_id' => ['required', 'uuid']],
            ['source_class_id.required' => 'Pilih kelas sumber.', 'source_class_id.uuid' => 'Pilih kelas sumber dari daftar.'],
        );

        $result = $this->attempt(fn (): array => $subjects->copyFrom($this->tenantId(), $target->id, $request->string('source_class_id')->value()));

        $message = "{$result['copied']} mata pelajaran disalin dari kelas {$result['source']}";
        $message .= $result['teachers'] > 0 ? ", {$result['teachers']} dengan guru pengampunya." : '.';

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} dilewati (sudah ada, nonaktif, atau bukan untuk tingkat ini).";
        }

        return $this->done($message.' Jadwal tidak ikut disalin.');
    }

    public function update(Request $request, string $classSubject, ManageClassSubjects $subjects): RedirectResponse
    {
        $row = $this->findRow($classSubject);

        $request->validate(['teacher_employee_id' => ['nullable', 'uuid']], [], ['teacher_employee_id' => 'guru pengampu']);

        $teacher = $request->input('teacher_employee_id');

        $changed = $this->attempt(fn (): bool => $subjects->setTeacher(
            $this->tenantId(),
            $row->id,
            is_string($teacher) && $teacher !== '' ? $teacher : null,
        ));

        return $this->done($changed ? 'Guru pengampu disimpan.' : 'Tidak ada data yang berubah.');
    }

    public function destroy(string $classSubject, ManageClassSubjects $subjects): RedirectResponse
    {
        $row = $this->findRow($classSubject);

        $name = $this->attempt(fn (): string => $subjects->remove($this->tenantId(), $row->id));

        return $this->done("Mata pelajaran {$name} dilepas dari kelas.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function findRow(string $classSubjectId): ClassSubject
    {
        $row = Str::isUuid($classSubjectId) ? ClassSubject::query()->find($classSubjectId) : null;

        if (! $row instanceof ClassSubject) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $row);

        return $row;
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
