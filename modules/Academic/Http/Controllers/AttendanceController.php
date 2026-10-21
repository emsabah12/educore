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
use Modules\Academic\Application\Attendance\AttendanceRecap;
use Modules\Academic\Application\Attendance\ManageAttendance;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Domain\Attendance\AttendanceStatus;
use Modules\Academic\Domain\Attendance\Exceptions\AttendanceException;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Presensi harian (PRD-002 §17 AC-4a), dua pintu dengan aturan yang sama:
 * - Operator: `/kelas/{class}/presensi` — `academic.classes.manage` di lembaga kelas (Gate dengan
 *   datanya: di luar cakupan 404, tanpa izin 403); tanggal mana pun di tahun ajaran.
 * - Wali kelas: `/kelas-saya/{class}/presensi` — hanya kelas yang ia walikan (selain itu 404);
 *   7 hari terakhir.
 *
 * Rekap (AC-4b): pemilik `academic.students.view` di lembaga kelas, atau wali kelas lewat Kelas saya.
 */
final class AttendanceController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(Request $request, string $class, ManageAttendance $attendance): Response
    {
        $target = $this->managedClass($class);

        return $this->sheet($request, $target, false, $attendance, "/kelas/{$target->id}/presensi", "/kelas/{$target->id}", 'Detail kelas');
    }

    public function store(Request $request, string $class, ManageAttendance $attendance): RedirectResponse
    {
        $target = $this->managedClass($class);

        return $this->save($request, $target, false, $attendance, 'academic.attendance.show');
    }

    public function showMine(Request $request, string $class, MyClasses $myClasses, ManageAttendance $attendance): Response
    {
        $target = $this->homeroomClass($class, $myClasses);

        return $this->sheet($request, $target, true, $attendance, "/kelas-saya/{$target->id}/presensi", "/kelas-saya/{$target->id}", "Kelas {$target->name}");
    }

    public function storeMine(Request $request, string $class, MyClasses $myClasses, ManageAttendance $attendance): RedirectResponse
    {
        $target = $this->homeroomClass($class, $myClasses);

        return $this->save($request, $target, true, $attendance, 'academic.my-attendance.show');
    }

    // ── Rekap (AC-4b) ────────────────────────────────────────────────────────

    /**
     * Rekap kelas untuk pemilik izin lihat siswa di lembaga kelas (Kepala Lembaga, operator, dll.).
     */
    public function recap(Request $request, string $class, AttendanceRecap $recap): Response
    {
        $target = Str::isUuid($class) ? SchoolClass::query()->find($class) : null;

        if (! $target instanceof SchoolClass) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::STUDENTS_VIEW, $target);

        return $this->recapPage($request, $target, $recap, "/kelas/{$target->id}/rekap-presensi", "/kelas/{$target->id}", 'Detail kelas');
    }

    public function recapMine(Request $request, string $class, MyClasses $myClasses, AttendanceRecap $recap): Response
    {
        $target = $this->homeroomClass($class, $myClasses);

        return $this->recapPage($request, $target, $recap, "/kelas-saya/{$target->id}/rekap-presensi", "/kelas-saya/{$target->id}", "Kelas {$target->name}");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function recapPage(Request $request, SchoolClass $class, AttendanceRecap $recap, string $baseUrl, string $backUrl, string $backLabel): Response
    {
        $periods = $recap->periods($class);
        $period = AttendanceRecap::pick($periods, $request->query('periode'));
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $organization = (string) DB::table('organizations')->where('id', $class->organization_id)->value('name');

        $options = [];

        foreach ($periods as $option) {
            $options[] = ['value' => $option['value'], 'label' => $option['label']];
        }

        return Inertia::render('academic/attendance/recap', [
            'class' => ['id' => $class->id, 'name' => $class->name, 'organization' => $organization, 'year' => $year->name],
            'periods' => $options,
            'period' => $period,
            'recap' => $recap->recap($class, $period['start'], $period['end']),
            'baseUrl' => $baseUrl,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    private function sheet(Request $request, SchoolClass $class, bool $asHomeroom, ManageAttendance $attendance, string $baseUrl, string $backUrl, string $backLabel): Response
    {
        $window = $attendance->window($class, $asHomeroom);
        $date = ManageAttendance::pickDate($request->query('tanggal'), $window);
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $organization = (string) DB::table('organizations')->where('id', $class->organization_id)->value('name');

        return Inertia::render('academic/attendance/sheet', [
            'class' => ['id' => $class->id, 'name' => $class->name, 'organization' => $organization, 'year' => $year->name],
            'window' => $window,
            'date' => $date,
            'sheet' => $date === null ? null : $attendance->sheet($class, $date),
            'statuses' => AttendanceStatus::options(),
            'asHomeroom' => $asHomeroom,
            'homeroomDays' => ManageAttendance::HOMEROOM_DAYS,
            'noteMax' => ManageAttendance::NOTE_MAX_LENGTH,
            'baseUrl' => $baseUrl,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    private function save(Request $request, SchoolClass $class, bool $asHomeroom, ManageAttendance $attendance, string $redirectRoute): RedirectResponse
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
            $counts = $attendance->save($context->tenantId, $class->id, $date, $asHomeroom, $context->membershipId, $entries);
        } catch (AttendanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Presensi {$class->name} tanggal {$date} disimpan: {$counts['H']} hadir, {$counts['S']} sakit, {$counts['I']} izin, {$counts['A']} alpa.",
        ]);

        return redirect()->route($redirectRoute, ['class' => $class->id, 'tanggal' => $date]);
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

    /**
     * Kelas yang diwalikan pengguna ini (Kelas saya, AC-3d); selain itu 404.
     */
    private function homeroomClass(string $classId, MyClasses $myClasses): SchoolClass
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $picked = $employeeId === null ? null : MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $classId);

        if ($picked === null || ! $picked['homeroom']) {
            abort(404);
        }

        return SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($picked['id'])->firstOrFail();
    }
}
