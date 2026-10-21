<?php

namespace Modules\Academic\Application\Classroom;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Application\Audit\AuditLogger;

/**
 * Jadwal pelajaran mingguan per kelas (PRD-002 §16 AC-3c): tambah, ubah, hapus baris jadwal.
 *
 * Aturan [OWNER, 2026-10-21]:
 * - Jam bebas per baris: hari (Senin–Ahad), jam mulai–selesai (selesai sesudah mulai), mapel kelas itu.
 * - Bentrok kelas dan bentrok guru (seluruh yayasan) ditolak; lihat ScheduleConflicts.
 * - Kelas tahun ajaran yang sudah lewat hanya bisa dilihat.
 *
 * Hak akses (academic.classes.manage di lembaga kelas) dicek pemanggil lewat Gate.
 */
final class ManageSchedule
{
    public function __construct(
        private readonly ScheduleConflicts $conflicts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ClassException
     */
    public function add(string $tenantId, string $classId, ScheduleData $data): ScheduleEntry
    {
        [$start, $end] = $this->assertValidInput($data);

        return DB::transaction(function () use ($tenantId, $classId, $data, $start, $end): ScheduleEntry {
            [$class, $year] = $this->lockEditableClass($tenantId, $classId);
            $classSubject = $this->classSubjectOf($class, $data->classSubjectId);
            $this->assertNoClash($tenantId, $class, $year, $classSubject, $data->day, $start, $end, null);

            $entry = new ScheduleEntry;
            $entry->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $class->organization_id,
                'academic_year_id' => $class->academic_year_id,
                'class_id' => $class->id,
                'class_subject_id' => $classSubject->id,
                'day_of_week' => $data->day,
                'start_time' => $start,
                'end_time' => $end,
            ]);
            $entry->save();

            $this->record(AcademicAuditAction::SCHEDULE_ENTRY_ADDED, $class, $classSubject, $data->day, $start, $end);

            return $entry;
        });
    }

    /**
     * @return bool true bila berubah
     *
     * @throws ClassException
     */
    public function update(string $tenantId, string $entryId, ScheduleData $data): bool
    {
        [$start, $end] = $this->assertValidInput($data);

        return DB::transaction(function () use ($tenantId, $entryId, $data, $start, $end): bool {
            $entry = $this->lockEntry($tenantId, $entryId);
            [$class, $year] = $this->lockEditableClass($tenantId, $entry->class_id);
            $classSubject = $this->classSubjectOf($class, $data->classSubjectId);

            $unchanged = $entry->class_subject_id === $classSubject->id
                && $entry->day_of_week === $data->day
                && substr($entry->start_time, 0, 5) === $start
                && substr($entry->end_time, 0, 5) === $end;

            if ($unchanged) {
                return false;
            }

            $this->assertNoClash($tenantId, $class, $year, $classSubject, $data->day, $start, $end, $entry->id);

            $entry->fill([
                'class_subject_id' => $classSubject->id,
                'day_of_week' => $data->day,
                'start_time' => $start,
                'end_time' => $end,
            ]);
            $entry->save();

            $this->record(AcademicAuditAction::SCHEDULE_ENTRY_UPDATED, $class, $classSubject, $data->day, $start, $end);

            return true;
        });
    }

    /**
     * @throws ClassException
     */
    public function remove(string $tenantId, string $entryId): void
    {
        DB::transaction(function () use ($tenantId, $entryId): void {
            $entry = $this->lockEntry($tenantId, $entryId);
            [$class] = $this->lockEditableClass($tenantId, $entry->class_id);
            $classSubject = ClassSubject::query()->withoutGlobalScope('tenant')->whereKey($entry->class_subject_id)->firstOrFail();

            $entry->delete();

            $this->record(
                AcademicAuditAction::SCHEDULE_ENTRY_REMOVED,
                $class,
                $classSubject,
                $entry->day_of_week,
                substr($entry->start_time, 0, 5),
                substr($entry->end_time, 0, 5),
            );
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: string} jam mulai & selesai "HH:MM"
     */
    private function assertValidInput(ScheduleData $data): array
    {
        if ($data->day < 1 || $data->day > 7) {
            throw ClassException::make('day_of_week', 'Pilih hari.');
        }

        if ($data->start === null) {
            throw ClassException::make('start_time', 'Jam mulai wajib diisi, mis. 07:00.');
        }

        if ($data->end === null) {
            throw ClassException::make('end_time', 'Jam selesai wajib diisi, mis. 08:20.');
        }

        if ($data->end <= $data->start) {
            throw ClassException::make('end_time', 'Jam selesai harus sesudah jam mulai.');
        }

        return [$data->start, $data->end];
    }

    private function assertNoClash(string $tenantId, SchoolClass $class, AcademicYear $year, ClassSubject $classSubject, int $day, string $start, string $end, ?string $exceptEntryId): void
    {
        $classClash = $this->conflicts->classClash($class->id, $day, $start, $end, $exceptEntryId);

        if ($classClash !== null) {
            throw ClassException::make('start_time', $classClash);
        }

        if ($classSubject->teacher_employee_id === null) {
            return;
        }

        $this->conflicts->lockTeacher($classSubject->teacher_employee_id);
        $teacherClash = $this->conflicts->teacherClash($tenantId, $classSubject->teacher_employee_id, $year, $day, $start, $end, $exceptEntryId, null);

        if ($teacherClash !== null) {
            throw ClassException::make('start_time', $teacherClash);
        }
    }

    private function classSubjectOf(SchoolClass $class, string $classSubjectId): ClassSubject
    {
        $classSubject = Str::isUuid($classSubjectId)
            ? ClassSubject::query()->withoutGlobalScope('tenant')->where('class_id', $class->id)->whereKey($classSubjectId)->first()
            : null;

        if (! $classSubject instanceof ClassSubject) {
            throw ClassException::make('class_subject_id', 'Pilih mata pelajaran dari kelas ini.');
        }

        return $classSubject;
    }

    /**
     * @return array{0: SchoolClass, 1: AcademicYear}
     */
    private function lockEditableClass(string $tenantId, string $classId): array
    {
        $class = Str::isUuid($classId)
            ? SchoolClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classId)->lockForUpdate()->first()
            : null;

        if (! $class instanceof SchoolClass) {
            throw ClassException::make('form', 'Kelas tidak ditemukan di yayasan ini.');
        }

        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        if (ManageClasses::isPastYear($year)) {
            throw ClassException::pastYear();
        }

        return [$class, $year];
    }

    private function lockEntry(string $tenantId, string $entryId): ScheduleEntry
    {
        $entry = Str::isUuid($entryId)
            ? ScheduleEntry::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($entryId)->lockForUpdate()->first()
            : null;

        if (! $entry instanceof ScheduleEntry) {
            throw ClassException::make('form', 'Jadwal ini sudah tidak ada. Muat ulang halaman.');
        }

        return $entry;
    }

    private function record(string $action, SchoolClass $class, ClassSubject $classSubject, int $day, string $start, string $end): void
    {
        $subject = (string) DB::table('ac_subjects')->where('id', $classSubject->subject_id)->value('name');

        $this->audit->record(
            $action,
            AcademicAuditAction::SUBJECT_CLASS,
            $class->id,
            [
                'kelas' => $class->name,
                'mapel' => $subject,
                'hari' => ScheduleEntry::dayName($day),
                'jam' => "{$start}–{$end}",
            ],
            organizationId: $class->organization_id,
            tenantId: $class->tenant_id,
        );
    }
}
