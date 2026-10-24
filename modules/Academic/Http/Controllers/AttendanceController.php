<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Attendance\AttendanceModeSetting;
use Modules\Academic\Application\Attendance\AttendanceRecap;
use Modules\Academic\Application\Attendance\ManageAttendance;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\SchoolDays;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Application\Teaching\ManageTeachingRecords;
use Modules\Academic\Application\Teaching\SubstituteTasks;
use Modules\Academic\Domain\Attendance\AttendanceStatus;
use Modules\Academic\Domain\Attendance\Exceptions\AttendanceException;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Presensi siswa (PRD-002 §17).
 *
 * Isi presensi — aturan sama, beberapa pintu:
 * - Harian (AC-4a): operator `/kelas/{class}/presensi` (`academic.classes.manage`, seluruh tahun
 *   ajaran); wali kelas `/kelas-saya/{class}/presensi` (7 hari terakhir).
 * - Per pertemuan (AC-4c): operator `/kelas/jadwal/{entry}/presensi`; guru pengampu mapel itu
 *   `/kelas-saya/jadwal/{entry}/presensi` (7 hari terakhir); guru pengganti (AC-6b-2)
 *   `/kelas-saya/menggantikan/{entry}/{tanggal}/presensi` (pertemuan yang ia gantikan saja, hari-H
 *   s.d. 6 hari sesudahnya). Pengampu yang mengisi otomatis tercatat Mengajar (PRD-002 §20.3).
 * Gate dengan datanya: di luar cakupan 404, tanpa izin 403. Pintu Kelas saya: bukan kelas/jadwal
 * yang ia pegang → 404. Mode lembaga menentukan jenis yang dibuka.
 *
 * Rekap (AC-4b/c): pemilik `academic.students.view` di lembaga kelas; wali kelas (semua) atau
 * pengampu (pertemuan mapelnya saja) lewat Kelas saya.
 */
final class AttendanceController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SchoolDays $schoolDays,
    ) {}

    // ── Harian ───────────────────────────────────────────────────────────────

    public function show(Request $request, string $class, ManageAttendance $attendance): Response
    {
        $target = $this->managedClass($class);

        return $this->sheet($request, $target, null, false, $attendance, "/kelas/{$target->id}/presensi", "/kelas/{$target->id}", 'Detail kelas');
    }

    public function store(Request $request, string $class, ManageAttendance $attendance): RedirectResponse
    {
        $target = $this->managedClass($class);

        return $this->save($request, $target, null, false, $attendance, route('academic.attendance.show', ['class' => $target->id]));
    }

    public function showMine(Request $request, string $class, MyClasses $myClasses, ManageAttendance $attendance): Response
    {
        $target = $this->myClass($class, $myClasses, homeroomOnly: true);

        return $this->sheet($request, $target, null, true, $attendance, "/kelas-saya/{$target->id}/presensi", "/kelas-saya/{$target->id}", "Kelas {$target->name}");
    }

    public function storeMine(Request $request, string $class, MyClasses $myClasses, ManageAttendance $attendance): RedirectResponse
    {
        $target = $this->myClass($class, $myClasses, homeroomOnly: true);

        return $this->save($request, $target, null, true, $attendance, route('academic.my-attendance.show', ['class' => $target->id]));
    }

    // ── Per pertemuan (AC-4c) ────────────────────────────────────────────────

    public function showLesson(Request $request, string $entry, ManageAttendance $attendance): Response
    {
        $lesson = $this->managedLesson($entry);
        $class = $this->classOf($lesson);

        return $this->sheet($request, $class, $lesson, false, $attendance, "/kelas/jadwal/{$lesson->id}/presensi", "/kelas/{$class->id}", 'Detail kelas');
    }

    public function storeLesson(Request $request, string $entry, ManageAttendance $attendance): RedirectResponse
    {
        $lesson = $this->managedLesson($entry);

        return $this->save($request, $this->classOf($lesson), $lesson, false, $attendance, route('academic.lesson-attendance.show', ['entry' => $lesson->id]));
    }

    public function showMyLesson(Request $request, string $entry, MyClasses $myClasses, ManageAttendance $attendance): Response
    {
        $lesson = $this->myLesson($entry, $myClasses);

        return $this->sheet($request, $this->classOf($lesson), $lesson, true, $attendance, "/kelas-saya/jadwal/{$lesson->id}/presensi", '/kelas-saya', 'Kelas saya');
    }

    public function storeMyLesson(Request $request, string $entry, MyClasses $myClasses, ManageAttendance $attendance, ManageTeachingRecords $teaching): RedirectResponse
    {
        $lesson = $this->myLesson($entry, $myClasses);
        $response = $this->save($request, $this->classOf($lesson), $lesson, true, $attendance, route('academic.my-lesson-attendance.show', ['entry' => $lesson->id]));

        // Presensi siswa tersimpan = guru mengajar pertemuan itu (AC-6b-2); catatan petugas tidak diubah.
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);

        if ($employeeId !== null) {
            $teaching->autoTaught($context->tenantId, $lesson->id, $request->string('date')->value(), $employeeId, $context->membershipId);
        }

        return $response;
    }

    // ── Guru pengganti (AC-6b-2) ─────────────────────────────────────────────

    public function showSubstitute(Request $request, string $entry, string $date, MyClasses $myClasses, SubstituteTasks $tasks, ManageAttendance $attendance): Response
    {
        $lesson = $this->substituteLesson($entry, $date, $myClasses, $tasks);
        $fillable = SubstituteTasks::fillable($date);

        return $this->sheet(
            $request,
            $this->classOf($lesson),
            $lesson,
            true,
            $attendance,
            "/kelas-saya/menggantikan/{$lesson->id}/{$date}/presensi",
            '/kelas-saya',
            'Kelas saya',
            fixedWindow: $fillable ? ['min' => $date, 'max' => $date] : null,
            fixedBlocked: $fillable ? null : 'Presensi pertemuan yang Anda gantikan bisa diisi pada hari-H sampai '.(ManageAttendance::HOMEROOM_DAYS - 1).' hari sesudahnya.',
            asSubstitute: true,
        );
    }

    public function storeSubstitute(Request $request, string $entry, string $date, MyClasses $myClasses, SubstituteTasks $tasks, ManageAttendance $attendance): RedirectResponse
    {
        $lesson = $this->substituteLesson($entry, $date, $myClasses, $tasks);

        if ($request->input('date') !== $date || ! SubstituteTasks::fillable($date)) {
            throw ValidationException::withMessages(['date' => 'Pengganti hanya bisa mengisi presensi pertemuan yang ia gantikan, pada hari-H sampai '.(ManageAttendance::HOMEROOM_DAYS - 1).' hari sesudahnya.']);
        }

        return $this->save($request, $this->classOf($lesson), $lesson, true, $attendance, route('academic.substitute-attendance.show', ['entry' => $lesson->id, 'date' => $date]));
    }

    // ── Rekap (AC-4b/c) ──────────────────────────────────────────────────────

    /**
     * Rekap kelas untuk pemilik izin lihat siswa di lembaga kelas (Kepala Lembaga, operator, dll.).
     */
    public function recap(Request $request, string $class, AttendanceRecap $recap, AttendanceModeSetting $mode, ManageAttendance $attendance): Response
    {
        $target = Str::isUuid($class) ? SchoolClass::query()->find($class) : null;

        if (! $target instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::STUDENTS_VIEW, $target);

        // Tautan ke tanggal yang belum diisi hanya untuk yang boleh mengisi presensi harian.
        $fill = Gate::allows(AcademicAccess::CLASSES_MANAGE, $target) ? $this->fillLink($target, false, "/kelas/{$target->id}/presensi", $attendance) : null;

        return $this->recapPage($request, $target, $recap, $mode, null, "/kelas/{$target->id}/rekap-presensi", "/kelas/{$target->id}", 'Detail kelas', $fill);
    }

    /**
     * Wali kelas: rekap harian & semua pertemuan. Pengampu (bukan wali kelas): pertemuan mapelnya saja.
     */
    public function recapMine(Request $request, string $class, MyClasses $myClasses, AttendanceRecap $recap, AttendanceModeSetting $mode, ManageAttendance $attendance): Response
    {
        [$target, $homeroom, $employeeId] = $this->myClassWithRole($class, $myClasses);

        $onlySubjects = null;

        if (! $homeroom) {
            $onlySubjects = [];

            foreach (ClassSubject::query()->withoutGlobalScope('tenant')->where('class_id', $target->id)->where('teacher_employee_id', $employeeId)->pluck('id') as $id) {
                $onlySubjects[] = (string) $id;
            }
        }

        $fill = $homeroom ? $this->fillLink($target, true, "/kelas-saya/{$target->id}/presensi", $attendance) : null;

        return $this->recapPage($request, $target, $recap, $mode, $onlySubjects, "/kelas-saya/{$target->id}/rekap-presensi", "/kelas-saya/{$target->id}", "Kelas {$target->name}", $fill);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @param  list<string>|null  $onlySubjects  null = semua; terisi = hanya pertemuan mapel ini (pengampu)
     * @param  array{url: string, min: string, max: string}|null  $fill  lembar presensi harian & tanggal yang boleh diisi
     */
    private function recapPage(Request $request, SchoolClass $class, AttendanceRecap $recap, AttendanceModeSetting $mode, ?array $onlySubjects, string $baseUrl, string $backUrl, string $backLabel, ?array $fill = null): Response
    {
        $periods = $recap->periods($class);
        $period = AttendanceRecap::pick($periods, $request->query('periode'));
        $subjects = $recap->subjectOptions($class, $onlySubjects);

        // Jenis: bawaan mengikuti mode lembaga; pengampu hanya per pertemuan.
        $kind = $request->query('jenis');
        $lesson = $onlySubjects !== null
            || $kind === 'pertemuan'
            || ($kind !== 'harian' && $mode->isLesson($class->tenant_id, $class->organization_id));

        $requestedSubject = $request->query('mapel');
        $subject = is_string($requestedSubject) && in_array($requestedSubject, array_column($subjects, 'value'), true) ? $requestedSubject : null;
        $subjectIds = $subject === null ? $onlySubjects : [$subject];

        $options = [];

        foreach ($periods as $option) {
            $options[] = ['value' => $option['value'], 'label' => $option['label']];
        }

        $kinds = [['value' => 'pertemuan', 'label' => 'Per pertemuan']];

        if ($onlySubjects === null) {
            array_unshift($kinds, ['value' => 'harian', 'label' => 'Harian']);
        }

        return Inertia::render('academic/attendance/recap', [
            'class' => $this->classInfo($class),
            'periods' => $options,
            'period' => $period,
            'kind' => $lesson ? 'pertemuan' : 'harian',
            'kinds' => $kinds,
            'subjects' => $subjects,
            'subject' => $subject,
            'recap' => $recap->recap($class, $period['start'], $period['end'], $lesson, $lesson ? $subjectIds : null),
            // Hari efektif & yang belum diisi (§18 P4): hanya rekap harian.
            'effective' => $lesson ? null : $recap->effective($class, $period['start'], $period['end']),
            'maxUnfilledListed' => AttendanceRecap::MAX_UNFILLED_LISTED,
            'fill' => $lesson ? null : $fill,
            'baseUrl' => $baseUrl,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    /**
     * @param  array{min: string, max: string}|null  $fixedWindow  rentang tetap (pengganti: satu tanggal)
     * @param  string|null  $fixedBlocked  alasan lembar tidak bisa diisi, menggantikan pemeriksaan biasa
     */
    private function sheet(Request $request, SchoolClass $class, ?ScheduleEntry $lesson, bool $limited, ManageAttendance $attendance, string $baseUrl, string $backUrl, string $backLabel, ?array $fixedWindow = null, ?string $fixedBlocked = null, bool $asSubstitute = false): Response
    {
        $blocked = $attendance->modeProblem($class, $lesson !== null) ?? $fixedBlocked;
        $window = $blocked === null ? ($fixedWindow ?? $attendance->window($class, $limited, $lesson?->day_of_week)) : null;
        $date = ManageAttendance::pickDate($request->query('tanggal'), $window, $lesson?->day_of_week);

        return Inertia::render('academic/attendance/sheet', [
            'class' => $this->classInfo($class),
            'lesson' => $lesson === null ? null : $this->lessonInfo($lesson),
            'blocked' => $blocked,
            'window' => $window,
            'date' => $date,
            'sheet' => $date === null ? null : $attendance->sheet($class, $date, $lesson),
            // Boleh diisi di hari libur, dengan peringatan (§18 P4).
            'holiday' => $date === null ? null : $this->schoolDays->holidayOn($this->yearOf($class), $date),
            'statuses' => AttendanceStatus::options(),
            'asHomeroom' => $limited,
            'homeroomDays' => ManageAttendance::HOMEROOM_DAYS,
            'asSubstitute' => $asSubstitute,
            'noteMax' => ManageAttendance::NOTE_MAX_LENGTH,
            'baseUrl' => $baseUrl,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    private function save(Request $request, SchoolClass $class, ?ScheduleEntry $lesson, bool $limited, ManageAttendance $attendance, string $redirectTo): RedirectResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'attendance' => ['required', 'array', 'max:200'],
            'attendance.*.status' => ['required', 'string', 'max:1'],
            'attendance.*.note' => ['nullable', 'string', 'max:'.ManageAttendance::NOTE_MAX_LENGTH],
        ], [
            'attendance.required' => 'Belum ada siswa yang diisi.',
            'attendance.*.status.required' => 'Pilih status kehadiran untuk setiap siswa.',
            'attendance.*.note.max' => 'Keterangan maksimal '.ManageAttendance::NOTE_MAX_LENGTH.' karakter.',
        ], ['date' => 'tanggal']);

        /** @var array<string, array{status: mixed, note: mixed}> $entries */
        $entries = [];

        foreach ((array) $request->input('attendance', []) as $enrollmentId => $entry) {
            $entries[(string) $enrollmentId] = [
                'status' => is_array($entry) ? ($entry['status'] ?? null) : null,
                'note' => is_array($entry) ? ($entry['note'] ?? null) : null,
            ];
        }

        $date = $request->string('date')->value();
        $context = $this->tenantContext->require();

        try {
            $counts = $attendance->save($context->tenantId, $class->id, $date, $limited, $context->membershipId, $entries, $lesson?->id);
        } catch (AttendanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Presensi {$class->name} tanggal {$date} disimpan: {$counts['H']} hadir, {$counts['S']} sakit, {$counts['I']} izin, {$counts['A']} alpa.",
        ]);

        return redirect()->to($redirectTo.'?tanggal='.$date);
    }

    /**
     * Lembar presensi harian & rentang tanggal yang boleh diisi pemanggil, atau null bila tidak bisa
     * (mode lembaga per pertemuan, tahun lewat/belum mulai).
     *
     * @return array{url: string, min: string, max: string}|null
     */
    private function fillLink(SchoolClass $class, bool $asHomeroom, string $url, ManageAttendance $attendance): ?array
    {
        if ($attendance->modeProblem($class, false) !== null) {
            return null;
        }

        $window = $attendance->window($class, $asHomeroom);

        return $window === null ? null : ['url' => $url, 'min' => $window['min'], 'max' => $window['max']];
    }

    private function yearOf(SchoolClass $class): AcademicYear
    {
        return AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
    }

    /**
     * @return array{id: string, name: string, organization: string, year: string}
     */
    private function classInfo(SchoolClass $class): array
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        return [
            'id' => $class->id,
            'name' => $class->name,
            'organization' => (string) DB::table('organizations')->where('id', $class->organization_id)->value('name'),
            'year' => $year->name,
        ];
    }

    /**
     * @return array{id: string, subject: string, day: int, day_label: string, start: string, end: string, teacher: string|null}
     */
    private function lessonInfo(ScheduleEntry $lesson): array
    {
        $subject = (string) DB::table('ac_class_subjects as cs')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.id', $lesson->class_subject_id)
            ->value('s.name');
        $teacherId = ClassSubject::query()->withoutGlobalScope('tenant')->whereKey($lesson->class_subject_id)->value('teacher_employee_id');
        $teacher = is_string($teacherId) ? (app(ActiveEmployees::class)->describe($lesson->tenant_id, [$teacherId])[$teacherId]['name'] ?? null) : null;

        return [
            'id' => $lesson->id,
            'subject' => $subject,
            'day' => $lesson->day_of_week,
            'day_label' => ScheduleEntry::dayName($lesson->day_of_week),
            'start' => substr($lesson->start_time, 0, 5),
            'end' => substr($lesson->end_time, 0, 5),
            'teacher' => $teacher,
        ];
    }

    private function managedClass(string $classId): SchoolClass
    {
        $class = Str::isUuid($classId) ? SchoolClass::query()->find($classId) : null;

        if (! $class instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $class);

        return $class;
    }

    private function managedLesson(string $entryId): ScheduleEntry
    {
        $lesson = Str::isUuid($entryId) ? ScheduleEntry::query()->find($entryId) : null;

        if (! $lesson instanceof ScheduleEntry) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $lesson);

        return $lesson;
    }

    private function classOf(ScheduleEntry $lesson): SchoolClass
    {
        return SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($lesson->class_id)->firstOrFail();
    }

    /**
     * Kelas yang dipegang pengguna ini (Kelas saya, AC-3d); selain itu 404.
     */
    private function myClass(string $classId, MyClasses $myClasses, bool $homeroomOnly): SchoolClass
    {
        [$class, $homeroom] = $this->myClassWithRole($classId, $myClasses);

        if ($homeroomOnly && ! $homeroom) {
            abort(404);
        }

        return $class;
    }

    /**
     * @return array{0: SchoolClass, 1: bool, 2: string} kelas, apakah wali kelasnya, id pegawai
     */
    private function myClassWithRole(string $classId, MyClasses $myClasses): array
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);

        if ($employeeId === null) {
            abort(404);
        }

        $picked = MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $classId);

        if ($picked === null) {
            abort(404);
        }

        $class = SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($picked['id'])->firstOrFail();

        return [$class, $picked['homeroom'], $employeeId];
    }

    /**
     * Baris jadwal yang pertemuannya pada tanggal itu tercatat "Digantikan" oleh pengguna ini; selain itu 404.
     */
    private function substituteLesson(string $entryId, string $date, MyClasses $myClasses, SubstituteTasks $tasks): ScheduleEntry
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $lesson = Str::isUuid($entryId) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? ScheduleEntry::query()->withoutGlobalScope('tenant')->where('tenant_id', $context->tenantId)->whereKey($entryId)->first()
            : null;

        if ($employeeId === null || ! $lesson instanceof ScheduleEntry || $tasks->find($context->tenantId, $employeeId, $lesson->id, $date) === null) {
            abort(404);
        }

        return $lesson;
    }

    /**
     * Baris jadwal yang mapelnya diampu pengguna ini, di kelas yang ia pegang; selain itu 404.
     */
    private function myLesson(string $entryId, MyClasses $myClasses): ScheduleEntry
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $lesson = Str::isUuid($entryId)
            ? ScheduleEntry::query()->withoutGlobalScope('tenant')->where('tenant_id', $context->tenantId)->whereKey($entryId)->first()
            : null;

        if ($employeeId === null || ! $lesson instanceof ScheduleEntry) {
            abort(404);
        }

        $teaches = ClassSubject::query()->withoutGlobalScope('tenant')->whereKey($lesson->class_subject_id)->where('teacher_employee_id', $employeeId)->exists();

        if (! $teaches || MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $lesson->class_id) === null) {
            abort(404);
        }

        return $lesson;
    }
}
