<?php

namespace Modules\Academic\Application\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Classroom\ManageClasses;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Academic\Domain\Attendance\AttendanceSession;
use Modules\Academic\Domain\Attendance\AttendanceStatus;
use Modules\Academic\Domain\Attendance\Exceptions\AttendanceException;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;

/**
 * Presensi per kelas (PRD-002 §17): harian (AC-4a) atau per pertemuan/baris jadwal (AC-4c, $lesson
 * terisi) — lembar presensi satu tanggal dan penyimpanannya.
 *
 * Aturan [OWNER, 2026-10-21]:
 * - Kode H/S/I/A; keterangan opsional maks. 200 karakter (isinya tidak masuk audit).
 * - Wali kelas: hari ini s.d. 6 hari sebelumnya (7 hari, hari ini termasuk). Operator: tanggal mana
 *   pun di tahun ajaran kelas. Keduanya tidak di masa depan dan hanya untuk tahun ajaran yang belum lewat.
 * - Daftar siswa = yang tercatat di kelas pada tanggal itu; simpan semua siswa sekaligus.
 * - Mode lembaga (academic.attendance_mode): HARIAN hanya menerima presensi harian, PER_PERTEMUAN
 *   hanya per pertemuan. Tanggal pertemuan wajib jatuh pada hari jadwalnya.
 *
 * Hak akses (wali kelas lewat Kelas saya, atau academic.classes.manage) dicek pemanggil.
 *
 * @phpstan-type SheetRow array{enrollment_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, status: string, note: string|null}
 * @phpstan-type Window array{min: string, max: string}|null
 */
final class ManageAttendance
{
    public const NOTE_MAX_LENGTH = 200;

    /** Wali kelas boleh mengisi/mengoreksi hari ini dan 6 hari sebelumnya. */
    public const HOMEROOM_DAYS = 7;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttendanceModeSetting $mode,
    ) {}

    /**
     * Pesan bila jenis presensi ini tidak dipakai lembaga kelas (mode berbeda), atau null.
     */
    public function modeProblem(SchoolClass $class, bool $lesson): ?string
    {
        $isLesson = $this->mode->isLesson($class->tenant_id, $class->organization_id);

        if ($isLesson && ! $lesson) {
            return 'Lembaga ini memakai presensi per pertemuan: presensi diisi guru pengampu dari jadwal pelajaran.';
        }

        if (! $isLesson && $lesson) {
            return 'Lembaga ini memakai presensi harian: presensi diisi wali kelas sekali sehari.';
        }

        return null;
    }

    /**
     * Rentang tanggal yang boleh diisi, atau null bila tidak ada (tahun lewat / belum mulai).
     * Untuk pertemuan ($day terisi): ujung rentang digeser ke hari jadwal terdekat di dalamnya.
     *
     * @return Window
     */
    public function window(SchoolClass $class, bool $asHomeroom, ?int $day = null): ?array
    {
        $year = $this->yearOf($class);

        if (ManageClasses::isPastYear($year)) {
            return null;
        }

        $today = BusinessClock::today();
        $max = CarbonImmutable::parse(min($today->toDateString(), $year->end_date->toDateString()));
        $min = CarbonImmutable::parse($year->start_date->toDateString());

        if ($asHomeroom) {
            $min = CarbonImmutable::parse(max($min->toDateString(), $today->subDays(self::HOMEROOM_DAYS - 1)->toDateString()));
        }

        if ($day !== null) {
            while ($max->dayOfWeekIso !== $day && $max >= $min) {
                $max = $max->subDay();
            }

            while ($min->dayOfWeekIso !== $day && $min <= $max) {
                $min = $min->addDay();
            }
        }

        return $min <= $max ? ['min' => $min->toDateString(), 'max' => $max->toDateString()] : null;
    }

    /**
     * Tanggal dari URL bila sah, di dalam rentang, dan (untuk pertemuan) jatuh pada hari jadwalnya;
     * selain itu tanggal terakhir yang boleh diisi.
     *
     * @param  Window  $window
     */
    public static function pickDate(mixed $requested, ?array $window, ?int $day = null): ?string
    {
        if ($window === null) {
            return null;
        }

        if (is_string($requested) && StudentInput::isDate($requested) && $requested >= $window['min'] && $requested <= $window['max']
            && ($day === null || CarbonImmutable::parse($requested)->dayOfWeekIso === $day)) {
            return $requested;
        }

        return $window['max'];
    }

    /**
     * Lembar presensi: siswa di kelas pada tanggal itu beserta status tersimpan (bawaan Hadir).
     *
     * @return array{students: list<SheetRow>, session: array{recorded_by: string|null, updated_at: string|null}|null}
     */
    public function sheet(SchoolClass $class, string $date, ?ScheduleEntry $lesson = null): array
    {
        $session = $this->session($class->id, $date, $lesson);
        $saved = [];

        if ($session !== null) {
            foreach (DB::table('ac_attendance_records')->where('session_id', $session->id)->get(['enrollment_id', 'status', 'note']) as $record) {
                $saved[(string) $record->enrollment_id] = [
                    'status' => (string) $record->status,
                    'note' => $record->note === null ? null : (string) $record->note,
                ];
            }
        }

        $students = [];

        foreach ($this->roster($class->id, $date) as $row) {
            $students[] = $row + [
                'status' => $saved[$row['enrollment_id']]['status'] ?? AttendanceStatus::Present->value,
                'note' => $saved[$row['enrollment_id']]['note'] ?? null,
            ];
        }

        return [
            'students' => $students,
            'session' => $session === null ? null : [
                'recorded_by' => $session->recorded_by_membership_id === null ? null : $this->memberName($session->recorded_by_membership_id),
                'updated_at' => $session->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, array{status: mixed, note: mixed}>  $entries  id pendaftaran => isian
     * @param  string|null  $lessonId  baris jadwal (presensi per pertemuan), null = harian
     * @return array{H: int, S: int, I: int, A: int}
     *
     * @throws AttendanceException
     */
    public function save(string $tenantId, string $classId, string $date, bool $asHomeroom, string $membershipId, array $entries, ?string $lessonId = null): array
    {
        try {
            return DB::transaction(function () use ($tenantId, $classId, $date, $asHomeroom, $membershipId, $entries, $lessonId): array {
                $class = Str::isUuid($classId)
                    ? SchoolClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classId)->lockForUpdate()->first()
                    : null;

                if (! $class instanceof SchoolClass) {
                    throw AttendanceException::make('form', 'Kelas tidak ditemukan di yayasan ini.');
                }

                $lesson = $lessonId === null ? null : $this->lockLesson($class, $lessonId);
                $modeProblem = $this->modeProblem($class, $lesson !== null);

                if ($modeProblem !== null) {
                    throw AttendanceException::make('form', $modeProblem);
                }

                $this->assertDate($class, $date, $asHomeroom, $lesson);
                $rows = $this->validatedRows($this->roster($class->id, $date), $entries);

                $session = $this->session($class->id, $date, $lesson, lock: true);
                $correction = $session !== null;

                if ($session === null) {
                    $session = new AttendanceSession;
                    $session->fill([
                        'tenant_id' => $tenantId,
                        'organization_id' => $class->organization_id,
                        'academic_year_id' => $class->academic_year_id,
                        'class_id' => $class->id,
                        'date' => $date,
                    ]);

                    // Pertemuan menyimpan mapel & jam saat diisi agar riwayat tidak berubah bila jadwal diubah.
                    if ($lesson !== null) {
                        $session->fill([
                            'schedule_entry_id' => $lesson->id,
                            'class_subject_id' => $lesson->class_subject_id,
                            'start_time' => $lesson->start_time,
                            'end_time' => $lesson->end_time,
                        ]);
                    }
                }

                $session->recorded_by_membership_id = $membershipId;
                $session->updated_at = CarbonImmutable::now();
                $session->save();

                DB::table('ac_attendance_records')->where('session_id', $session->id)->delete();
                $now = now();
                $counts = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
                $insert = [];

                foreach ($rows as $enrollmentId => $row) {
                    match ($row['status']) {
                        AttendanceStatus::Present => $counts['H']++,
                        AttendanceStatus::Sick => $counts['S']++,
                        AttendanceStatus::Permitted => $counts['I']++,
                        AttendanceStatus::Absent => $counts['A']++,
                    };
                    $insert[] = [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'organization_id' => $class->organization_id,
                        'session_id' => $session->id,
                        'enrollment_id' => $enrollmentId,
                        'status' => $row['status']->value,
                        'note' => $row['note'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insert !== []) {
                    DB::table('ac_attendance_records')->insert($insert);
                }

                // Isi keterangan bisa memuat hal pribadi (alasan sakit): audit hanya mencatat jumlah.
                $this->audit->record(
                    AcademicAuditAction::ATTENDANCE_RECORDED,
                    AcademicAuditAction::SUBJECT_CLASS,
                    $class->id,
                    array_merge([
                        'kelas' => $class->name,
                        'tanggal' => $date,
                        'hadir' => $counts['H'],
                        'sakit' => $counts['S'],
                        'izin' => $counts['I'],
                        'alpa' => $counts['A'],
                        'koreksi' => $correction,
                    ], $lesson === null ? [] : [
                        'mapel' => $this->subjectName($lesson->class_subject_id),
                        'jam' => substr((string) $session->start_time, 0, 5).'–'.substr((string) $session->end_time, 0, 5),
                    ]),
                    organizationId: $class->organization_id,
                    tenantId: $tenantId,
                );

                return $counts;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua orang menyimpan presensi pertama sesi yang sama bersamaan.
            throw AttendanceException::make('form', 'Presensi tanggal ini baru saja disimpan orang lain. Muat ulang halaman lalu periksa lagi.');
        }
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function assertDate(SchoolClass $class, string $date, bool $asHomeroom, ?ScheduleEntry $lesson): void
    {
        if (! StudentInput::isDate($date)) {
            throw AttendanceException::make('date', 'Tanggal presensi tidak valid.');
        }

        if ($lesson !== null && CarbonImmutable::parse($date)->dayOfWeekIso !== $lesson->day_of_week) {
            throw AttendanceException::make('date', 'Tanggal harus hari '.ScheduleEntry::dayName($lesson->day_of_week).', sesuai jadwal pelajaran ini.');
        }

        $window = $this->window($class, $asHomeroom);

        if ($window === null) {
            throw AttendanceException::make('date', 'Presensi kelas ini tidak bisa diisi (tahun ajaran sudah lewat atau belum mulai).');
        }

        if ($date > $window['max']) {
            throw AttendanceException::make('date', 'Presensi tidak bisa diisi untuk tanggal di masa depan atau sesudah tahun ajaran selesai.');
        }

        if ($date < $window['min']) {
            throw AttendanceException::make('date', $asHomeroom
                ? ($lesson === null ? 'Wali kelas' : 'Guru pengampu').' hanya bisa mengisi atau mengoreksi presensi '.self::HOMEROOM_DAYS.' hari terakhir. Minta Operator Akademik untuk tanggal yang lebih lama.'
                : 'Tanggal presensi harus di dalam tahun ajaran kelas ini.');
        }
    }

    /**
     * @param  list<array{enrollment_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null}>  $roster
     * @param  array<string, array{status: mixed, note: mixed}>  $entries
     * @return array<string, array{status: AttendanceStatus, note: string|null}>
     */
    private function validatedRows(array $roster, array $entries): array
    {
        if ($roster === []) {
            throw AttendanceException::make('form', 'Belum ada siswa di kelas ini pada tanggal tersebut.');
        }

        $known = [];

        foreach ($roster as $student) {
            $known[$student['enrollment_id']] = $student['name'];
        }

        foreach (array_keys($entries) as $enrollmentId) {
            if (! isset($known[$enrollmentId])) {
                throw AttendanceException::make('form', 'Daftar siswa kelas ini berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.');
            }
        }

        $rows = [];
        $missing = [];

        foreach ($known as $enrollmentId => $name) {
            $entry = $entries[$enrollmentId] ?? null;

            if ($entry === null) {
                $missing[] = $name;

                continue;
            }

            $status = AttendanceStatus::tryFrom(is_string($entry['status']) ? $entry['status'] : '');

            if ($status === null) {
                $missing[] = $name;

                continue;
            }

            $note = is_string($entry['note']) ? trim((string) preg_replace('/\s+/u', ' ', $entry['note'])) : '';

            if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
                throw AttendanceException::make('form', "Keterangan {$name} maksimal ".self::NOTE_MAX_LENGTH.' karakter.');
            }

            $rows[$enrollmentId] = ['status' => $status, 'note' => $note === '' ? null : $note];
        }

        if ($missing !== []) {
            throw AttendanceException::make('form', 'Pilih status kehadiran untuk: '.implode(', ', $missing).'.');
        }

        return $rows;
    }

    /**
     * Siswa yang tercatat di kelas pada tanggal itu: masuk kelas ≤ tanggal dan belum keluar/pindah
     * pada tanggal itu (pindah kelas di tanggal X = sudah di kelas baru pada tanggal X).
     *
     * @return list<array{enrollment_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null}>
     */
    private function roster(string $classId, string $date): array
    {
        $rows = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $classId)
            ->where('cm.start_date', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('cm.end_date')->orWhere('cm.end_date', '>', $date);
            })
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.student_id', 'e.student_number', 's.nisn', 'p.name', 'p.gender']);

        $students = [];

        foreach ($rows as $row) {
            $students[] = [
                'enrollment_id' => (string) $row->id,
                'student_id' => (string) $row->student_id,
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
            ];
        }

        return $students;
    }

    /**
     * Sesi harian (kelas + tanggal) atau sesi pertemuan (baris jadwal + tanggal).
     */
    private function session(string $classId, string $date, ?ScheduleEntry $lesson, bool $lock = false): ?AttendanceSession
    {
        $query = AttendanceSession::query()
            ->withoutGlobalScope('tenant')
            ->where('class_id', $classId)
            ->whereDate('date', $date);

        if ($lesson === null) {
            $query->whereNull('class_subject_id');
        } else {
            $query->where('schedule_entry_id', $lesson->id);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function lockLesson(SchoolClass $class, string $lessonId): ScheduleEntry
    {
        $lesson = Str::isUuid($lessonId)
            ? ScheduleEntry::query()->withoutGlobalScope('tenant')->where('class_id', $class->id)->whereKey($lessonId)->lockForUpdate()->first()
            : null;

        if (! $lesson instanceof ScheduleEntry) {
            throw AttendanceException::make('form', 'Jadwal pelajaran ini sudah tidak ada. Muat ulang halaman.');
        }

        return $lesson;
    }

    private function subjectName(string $classSubjectId): string
    {
        return (string) DB::table('ac_class_subjects as cs')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.id', $classSubjectId)
            ->value('s.name');
    }

    private function yearOf(SchoolClass $class): AcademicYear
    {
        return AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
    }

    private function memberName(string $membershipId): ?string
    {
        $name = DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.id', $membershipId)
            ->value('p.name');

        return $name === null ? null : (string) $name;
    }
}
