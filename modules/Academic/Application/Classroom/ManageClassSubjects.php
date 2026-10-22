<?php

namespace Modules\Academic\Application\Classroom;

use Closure;
use Illuminate\Database\Query\Builder;
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
 * - Mengganti pengampu diperiksa bentroknya terhadap jadwal guru baru; melepas mapel ikut menghapus
 *   jadwalnya (AC-3c).
 * - Salin dari kelas lain (PRD-002 §18 P3): kelas sumber di lembaga yang sama (tahun mana pun); yang
 *   disalin hanya mapel aktif yang cocok tingkatnya & belum ada, pengampu ikut bila masih aktif di
 *   lembaga itu. Jadwal tidak disalin.
 *
 * Hak akses (academic.classes.manage di lembaga kelas) dicek pemanggil lewat Gate.
 */
final class ManageClassSubjects
{
    /** Batas pilihan kelas sumber yang dikirim ke halaman. */
    public const MAX_COPY_SOURCES = 200;

    public function __construct(
        private readonly ActiveEmployees $employees,
        private readonly ScheduleConflicts $conflicts,
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
     * Pilihan kelas sumber untuk "Salin dari kelas lain" (PRD-002 §18 P3): kelas lain di lembaga yang
     * sama (tahun mana pun) yang punya mapel, urut tahun terbaru lalu tingkat & nama. Saran bawaan:
     * kelas setingkat di tahun ajaran sebelumnya (kalender yang sama), utamakan yang namanya sama.
     *
     * @return array{options: list<array{value: string, label: string}>, suggested: string|null}
     */
    public function copySources(SchoolClass $class): array
    {
        $rows = DB::table('ac_classes as c')
            ->join('ac_academic_years as y', 'y.id', '=', 'c.academic_year_id')
            ->where('c.organization_id', $class->organization_id)
            ->where('c.id', '!=', $class->id)
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('ac_class_subjects as cs')->whereColumn('cs.class_id', 'c.id'))
            ->orderByDesc('y.start_date')
            ->orderBy('c.grade')
            ->orderBy('c.name')
            ->limit(self::MAX_COPY_SOURCES)
            ->get(['c.id', 'c.name', 'c.grade', 'c.academic_year_id', 'y.name as year_name', 'y.calendar_id', 'y.start_date']);

        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $previous = AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('calendar_id', $year->calendar_id)
            ->where('start_date', '<', $year->start_date->toDateString())
            ->orderByDesc('start_date')
            ->first(['id']);

        $options = [];
        $suggested = null;
        $suggestedByName = null;

        foreach ($rows as $row) {
            $options[] = ['value' => (string) $row->id, 'label' => "{$row->name} — {$row->year_name}"];

            if ($previous !== null && $row->academic_year_id === $previous->id && (int) $row->grade === $class->grade) {
                $suggested ??= (string) $row->id;

                if ($suggestedByName === null && mb_strtolower((string) $row->name) === mb_strtolower($class->name)) {
                    $suggestedByName = (string) $row->id;
                }
            }
        }

        return ['options' => $options, 'suggested' => $suggestedByName ?? $suggested];
    }

    /**
     * Salin mapel & pengampu dari kelas lain ke kelas ini (PRD-002 §18 P3). Jadwal tidak disalin.
     *
     * @return array{copied: int, skipped: int, teachers: int, source: string}
     *
     * @throws ClassException
     */
    public function copyFrom(string $tenantId, string $classId, string $sourceClassId): array
    {
        return $this->guard(function () use ($tenantId, $classId, $sourceClassId): array {
            $class = $this->lockEditableClass($tenantId, $classId);
            $source = Str::isUuid($sourceClassId)
                ? SchoolClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($sourceClassId)->first()
                : null;

            if (! $source instanceof SchoolClass || $source->organization_id !== $class->organization_id) {
                throw ClassException::make('source_class_id', 'Kelas sumber harus kelas lain di lembaga yang sama.');
            }

            if ($source->id === $class->id) {
                throw ClassException::make('source_class_id', 'Pilih kelas lain sebagai sumber.');
            }

            $sourceYear = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($source->academic_year_id)->value('name');
            $sourceLabel = $source->name.(is_string($sourceYear) ? " ({$sourceYear})" : '');

            $sourceRows = DB::table('ac_class_subjects as cs')
                ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
                ->where('cs.class_id', $source->id)
                ->orderByRaw('lower(s.name)')
                ->orderBy('cs.id')
                ->get(['cs.subject_id', 'cs.teacher_employee_id']);

            if ($sourceRows->isEmpty()) {
                throw ClassException::make('source_class_id', "Kelas {$sourceLabel} belum punya mata pelajaran.");
            }

            $subjects = Subject::query()
                ->withoutGlobalScope('tenant')
                ->whereIn('id', $sourceRows->pluck('subject_id')->all())
                ->get()
                ->keyBy('id');
            $used = DB::table('ac_class_subjects')->where('class_id', $class->id)->pluck('subject_id')->all();
            $names = [];
            $teachers = 0;
            $skipped = 0;

            foreach ($sourceRows as $sourceRow) {
                $subject = $subjects->get((string) $sourceRow->subject_id);

                // Dilewati: mapel nonaktif, bukan untuk tingkat kelas ini, atau sudah ada.
                if (! $subject instanceof Subject || ! $subject->is_active || ! $subject->coversGrade($class->grade) || in_array($subject->id, $used, true)) {
                    $skipped++;

                    continue;
                }

                // Pengampu ikut hanya bila masih pegawai aktif yang ditempatkan di lembaga ini.
                $teacher = is_string($sourceRow->teacher_employee_id) && $this->employees->isActiveIn($tenantId, $sourceRow->teacher_employee_id, $class->organization_id)
                    ? $sourceRow->teacher_employee_id
                    : null;

                $row = new ClassSubject;
                $row->fill([
                    'tenant_id' => $tenantId,
                    'organization_id' => $class->organization_id,
                    'academic_year_id' => $class->academic_year_id,
                    'class_id' => $class->id,
                    'subject_id' => $subject->id,
                    'teacher_employee_id' => $teacher,
                ]);
                $row->save();

                $names[] = $subject->name;
                $teachers += $teacher === null ? 0 : 1;
            }

            if ($names === []) {
                throw ClassException::make('source_class_id', "Tidak ada mata pelajaran dari kelas {$sourceLabel} yang bisa disalin: semuanya sudah ada di kelas ini, nonaktif, atau bukan untuk tingkat {$class->grade}.");
            }

            $this->audit->record(
                AcademicAuditAction::CLASS_SUBJECTS_ADDED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                [
                    'kelas' => $class->name,
                    'jumlah' => count($names),
                    'mapel' => $names,
                    'disalin_dari' => $sourceLabel,
                    'dilewati' => $skipped,
                    'pengampu_ikut' => $teachers,
                ],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return ['copied' => count($names), 'skipped' => $skipped, 'teachers' => $teachers, 'source' => $sourceLabel];
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

            if ($employeeId !== null) {
                $this->assertTeacherFree($tenantId, $row, $class, $employeeId);
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

            // Riwayat presensi per pertemuan tidak boleh hilang (AC-4c).
            if (DB::table('ac_attendance_sessions')->where('class_subject_id', $row->id)->exists()) {
                throw ClassException::make('form', "Mata pelajaran {$subject->name} sudah punya presensi, jadi tidak bisa dilepas dari kelas. Hapus jadwalnya saja bila tidak dipakai lagi.");
            }

            // Jadwal mapel ini ikut terhapus (FK cascade, AC-3c).
            $scheduleRows = DB::table('ac_schedule_entries')->where('class_subject_id', $row->id)->count();

            $row->delete();

            $this->audit->record(
                AcademicAuditAction::CLASS_SUBJECT_REMOVED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'mapel' => $subject->name, 'jadwal_dihapus' => $scheduleRows],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $subject->name;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Pengampu baru tidak boleh bentrok dengan jadwalnya di tempat lain (AC-3c).
     */
    private function assertTeacherFree(string $tenantId, ClassSubject $row, SchoolClass $class, string $employeeId): void
    {
        $entries = DB::table('ac_schedule_entries')
            ->where('class_subject_id', $row->id)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get(['day_of_week', 'start_time', 'end_time']);

        if ($entries->isEmpty()) {
            return;
        }

        $this->conflicts->lockTeacher($employeeId);
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        foreach ($entries as $entry) {
            $clash = $this->conflicts->teacherClash(
                $tenantId,
                $employeeId,
                $year,
                (int) $entry->day_of_week,
                substr((string) $entry->start_time, 0, 5),
                substr((string) $entry->end_time, 0, 5),
                null,
                $row->id,
            );

            if ($clash !== null) {
                throw ClassException::make('teacher_employee_id', $clash);
            }
        }
    }

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
