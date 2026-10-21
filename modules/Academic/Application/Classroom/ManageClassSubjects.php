<?php

namespace Modules\Academic\Application\Classroom;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Subject\Subject;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Mata pelajaran kelas & guru pengampu (PRD-002 §16 AC-3b): tambah mapel ke kelas (banyak
 * sekaligus), ganti pengampu, lepas mapel dari kelas.
 *
 * Aturan [OWNER, 2026-10-21]:
 * - Mapel: aktif, di lembaga kelas, rentang tingkatnya memuat tingkat kelas; paling banyak sekali per kelas.
 * - Pengampu: opsional, satu per mapel per kelas; pegawai aktif yang ditempatkan di lembaga kelas
 *   (kontrak HR ActiveEmployees). Pengampu lama yang sudah tidak aktif boleh tetap tercatat sampai diganti.
 * - Kelas tahun ajaran yang sudah lewat hanya bisa dilihat.
 *
 * Hak akses (academic.classes.manage di lembaga kelas) dicek pemanggil lewat Gate.
 */
final class ManageClassSubjects
{
    public function __construct(
        private readonly ActiveEmployees $employees,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Mapel aktif lembaga kelas yang memuat tingkat kelas dan belum ada di kelas itu, urut nama.
     *
     * @return list<array{value: string, label: string}>
     */
    public function available(SchoolClass $class): array
    {
        $used = DB::table('ac_class_subjects')->where('class_id', $class->id)->pluck('subject_id')->all();

        $subjects = Subject::query()
            ->withoutGlobalScope('tenant')
            ->where('organization_id', $class->organization_id)
            ->where('is_active', true)
            ->whereNotIn('id', $used)
            ->orderByRaw('lower(name)')
            ->get();

        $options = [];

        foreach ($subjects as $subject) {
            if ($subject->coversGrade($class->grade)) {
                $options[] = ['value' => $subject->id, 'label' => $subject->code === null ? $subject->name : "{$subject->name} ({$subject->code})"];
            }
        }

        return $options;
    }

    /**
     * @param  list<string>  $subjectIds
     * @return int jumlah mapel yang ditambahkan
     *
     * @throws ClassException
     */
    public function add(string $tenantId, string $classId, array $subjectIds): int
    {
        $subjectIds = array_values(array_unique($subjectIds));

        if ($subjectIds === []) {
            throw ClassException::make('subject_ids', 'Pilih minimal satu mata pelajaran.');
        }

        return $this->guard(function () use ($tenantId, $classId, $subjectIds): int {
            $class = $this->lockEditableClass($tenantId, $classId);
            $subjects = Subject::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', array_values(array_filter($subjectIds, fn (string $id): bool => Str::isUuid($id))))
                ->get()
                ->keyBy('id');
            $used = DB::table('ac_class_subjects')->where('class_id', $class->id)->pluck('subject_id')->all();
            $problems = [];
            $names = [];

            foreach ($subjectIds as $subjectId) {
                $subject = $subjects->get($subjectId);

                if (! $subject instanceof Subject || $subject->organization_id !== $class->organization_id) {
                    $problems[] = 'mata pelajaran yang tidak ada di lembaga ini';
                } elseif (! $subject->is_active) {
                    $problems[] = "{$subject->name} (nonaktif)";
                } elseif (! $subject->coversGrade($class->grade)) {
                    $problems[] = "{$subject->name} (bukan untuk tingkat {$class->grade})";
                } elseif (in_array($subject->id, $used, true)) {
                    $problems[] = "{$subject->name} (sudah ada di kelas ini)";
                } else {
                    $names[] = $subject->name;
                }
            }

            // Semua atau tidak sama sekali: masalah disebut sekaligus.
            if ($problems !== []) {
                throw ClassException::make('subject_ids', count($problems).' mata pelajaran tidak bisa ditambahkan: '.implode(', ', array_values(array_unique($problems))).'.');
            }

            foreach ($subjectIds as $subjectId) {
                $row = new ClassSubject;
                $row->fill([
                    'tenant_id' => $tenantId,
                    'organization_id' => $class->organization_id,
                    'academic_year_id' => $class->academic_year_id,
                    'class_id' => $class->id,
                    'subject_id' => $subjectId,
                ]);
                $row->save();
            }

            $this->audit->record(
                AcademicAuditAction::CLASS_SUBJECTS_ADDED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'jumlah' => count($subjectIds), 'mapel' => $names],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return count($subjectIds);
        });
    }

    /**
     * Ganti (atau kosongkan) guru pengampu.
     *
     * @return bool true bila berubah
     *
     * @throws ClassException
     */
    public function setTeacher(string $tenantId, string $classSubjectId, ?string $employeeId): bool
    {
        return DB::transaction(function () use ($tenantId, $classSubjectId, $employeeId): bool {
            [$row, $class, $subject] = $this->lockRow($tenantId, $classSubjectId);

            if ($row->teacher_employee_id === $employeeId) {
                return false;
            }

            if ($employeeId !== null && (! Str::isUuid($employeeId) || ! $this->employees->isActiveIn($tenantId, $employeeId, $class->organization_id))) {
                throw ClassException::make('teacher_employee_id', 'Guru pengampu harus pegawai aktif yang ditempatkan di lembaga ini.');
            }

            $row->teacher_employee_id = $employeeId;
            $row->save();

            $this->audit->record(
                AcademicAuditAction::CLASS_SUBJECT_TEACHER_CHANGED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'mapel' => $subject->name, 'pengampu_diisi' => $employeeId !== null],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return true;
        });
    }

    /**
     * Lepas mapel dari kelas (mapelnya sendiri tetap ada).
     *
     * @throws ClassException
     */
    public function remove(string $tenantId, string $classSubjectId): string
    {
        return DB::transaction(function () use ($tenantId, $classSubjectId): string {
            [$row, $class, $subject] = $this->lockRow($tenantId, $classSubjectId);

            $row->delete();

            $this->audit->record(
                AcademicAuditAction::CLASS_SUBJECT_REMOVED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'mapel' => $subject->name],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $subject->name;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function lockEditableClass(string $tenantId, string $classId): SchoolClass
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

        return $class;
    }

    /**
     * @return array{0: ClassSubject, 1: SchoolClass, 2: Subject}
     */
    private function lockRow(string $tenantId, string $classSubjectId): array
    {
        $row = Str::isUuid($classSubjectId)
            ? ClassSubject::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classSubjectId)->lockForUpdate()->first()
            : null;

        if (! $row instanceof ClassSubject) {
            throw ClassException::make('form', 'Mata pelajaran ini sudah tidak ada di kelas tersebut. Muat ulang halaman.');
        }

        $class = $this->lockEditableClass($tenantId, $row->class_id);
        $subject = Subject::query()->withoutGlobalScope('tenant')->whereKey($row->subject_id)->firstOrFail();

        return [$row, $class, $subject];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guard(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            throw ClassException::make('subject_ids', 'Mata pelajaran ini baru saja ditambahkan ke kelas. Muat ulang halaman.');
        }
    }
}
