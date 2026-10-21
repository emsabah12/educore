<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Classroom\ManageSchedule;
use Modules\Academic\Application\Classroom\ScheduleData;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Jadwal pelajaran di detail kelas (PRD-002 §16 AC-3c): tambah, ubah, hapus baris jadwal.
 *
 * Mengelola: `academic.classes.manage` di lembaga kelas (Gate dengan datanya: di luar cakupan 404,
 * tanpa izin 403).
 */
final class ScheduleController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function store(Request $request, string $class, ManageSchedule $schedule): RedirectResponse
    {
        $target = Str::isUuid($class) ? SchoolClass::query()->find($class) : null;

        if (! $target instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $target);

        $this->validateInput($request);

        $this->attempt(fn (): ScheduleEntry => $schedule->add($this->tenantId(), $target->id, $this->data($request)));

        return $this->done('Jadwal ditambahkan.');
    }

    public function update(Request $request, string $entry, ManageSchedule $schedule): RedirectResponse
    {
        $row = $this->findEntry($entry);

        $this->validateInput($request);

        $changed = $this->attempt(fn (): bool => $schedule->update($this->tenantId(), $row->id, $this->data($request)));

        return $this->done($changed ? 'Jadwal disimpan.' : 'Tidak ada data yang berubah.');
    }

    public function destroy(string $entry, ManageSchedule $schedule): RedirectResponse
    {
        $row = $this->findEntry($entry);

        $this->attempt(function () use ($schedule, $row): bool {
            $schedule->remove($this->tenantId(), $row->id);

            return true;
        });

        return $this->done('Jadwal dihapus.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function validateInput(Request $request): void
    {
        $request->validate([
            'class_subject_id' => ['required', 'uuid'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'string', 'max:8'],
            'end_time' => ['required', 'string', 'max:8'],
        ], [
            'class_subject_id.required' => 'Pilih mata pelajaran.',
            'day_of_week.required' => 'Pilih hari.',
            'day_of_week.between' => 'Pilih hari.',
            'start_time.required' => 'Jam mulai wajib diisi.',
            'end_time.required' => 'Jam selesai wajib diisi.',
        ], [
            'class_subject_id' => 'mata pelajaran',
            'day_of_week' => 'hari',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
        ]);
    }

    private function data(Request $request): ScheduleData
    {
        return new ScheduleData(
            classSubjectId: $request->string('class_subject_id')->value(),
            day: $request->integer('day_of_week'),
            start: ScheduleData::cleanTime($request->input('start_time')),
            end: ScheduleData::cleanTime($request->input('end_time')),
        );
    }

    private function findEntry(string $entryId): ScheduleEntry
    {
        $entry = Str::isUuid($entryId) ? ScheduleEntry::query()->find($entryId) : null;

        if (! $entry instanceof ScheduleEntry) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $entry);

        return $entry;
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
