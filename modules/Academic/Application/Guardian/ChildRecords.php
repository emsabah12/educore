<?php

namespace Modules\Academic\Application\Guardian;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Attendance\AttendanceModeSetting;
use Modules\Academic\Domain\Attendance\AttendanceStatus;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Support\BusinessClock;

/**
 * Presensi & kelas anak untuk portal orang tua/wali (PRD-002 §21.3, AC-7b). Hanya baca.
 * Pemanggil WAJIB sudah memastikan siswa ini anak orang tua/wali yang login (MyChildren::name).
 *
 * - Kelas = semua kelas yang pernah diikuti anak (tahun ajaran terbaru dulu).
 * - Presensi per bulan mengikuti mode presensi lembaga kelas (harian atau per pertemuan, §17), hanya
 *   catatan milik anak itu; keterangan dari guru tidak ditampilkan [ASUMSI].
 *
 * @phpstan-type ChildClass array{id: string, name: string, organization: string, year: string, enrollment_id: string}
 * @phpstan-type AttendanceDay array{date: string, status: string, label: string, subject: string|null, time: string|null}
 */
final class ChildRecords
{
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AttendanceModeSetting $attendanceMode,
    ) {}

    /**
     * @return list<ChildClass>
     */
    public function classes(string $studentId): array
    {
        $rows = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_classes as c', 'c.id', '=', 'cm.class_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'cm.academic_year_id')
            ->join('organizations as o', 'o.id', '=', 'cm.organization_id')
            ->where('e.student_id', $studentId)
            ->orderByDesc('y.start_date')
            ->orderByRaw('cm.end_date IS NOT NULL')
            ->orderByDesc('cm.start_date')
            ->get(['c.id', 'c.name', 'o.name as organization', 'y.name as year', 'cm.enrollment_id']);

        $classes = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;

            // Keluar lalu masuk lagi ke kelas yang sama cukup sekali.
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $classes[] = [
                'id' => $id,
                'name' => (string) $row->name,
                'organization' => (string) $row->organization,
                'year' => (string) $row->year,
                'enrollment_id' => (string) $row->enrollment_id,
            ];
        }

        return $classes;
    }

    /**
     * Kelas dari URL bila milik anak ini; selain itu kelas terbaru.
     *
     * @param  list<ChildClass>  $classes
     * @return ChildClass|null
     */
    public static function pickClass(array $classes, mixed $requested): ?array
    {
        foreach ($classes as $class) {
            if (is_string($requested) && $class['id'] === $requested) {
                return $class;
            }
        }

        return $classes[0] ?? null;
    }

    /**
     * Pilihan bulan tahun ajaran kelas yang sudah mulai, terbaru dulu.
     *
     * @return list<array{value: string, label: string}>
     */
    public function months(SchoolClass $class): array
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $today = BusinessClock::todayString();
        $month = $year->start_date->startOfMonth();
        $months = [];

        while ($month->toDateString() <= $year->end_date->toDateString() && $month->toDateString() <= $today) {
            $months[] = ['value' => $month->format('Y-m'), 'label' => self::MONTHS[$month->month].' '.$month->year];
            $month = $month->addMonth();
        }

        return array_reverse($months);
    }

    /**
     * Presensi anak satu bulan di kelas itu.
     *
     * @param  list<string>  $enrollmentIds  pendaftaran anak
     * @return array{kind: 'hari'|'pertemuan', totals: array{H: int, S: int, I: int, A: int}, days: list<AttendanceDay>}
     */
    public function attendance(SchoolClass $class, array $enrollmentIds, string $month): array
    {
        $lesson = $this->attendanceMode->isLesson($class->tenant_id, $class->organization_id);
        // $month sudah dicocokkan pemanggil dengan months(); format salah → bulan berjalan.
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, BusinessClock::timezone()) ?? BusinessClock::today()->startOfMonth();

        $rows = DB::table('ac_attendance_records as r')
            ->join('ac_attendance_sessions as s', 's.id', '=', 'r.session_id')
            ->leftJoin('ac_class_subjects as cs', 'cs.id', '=', 's.class_subject_id')
            ->leftJoin('ac_subjects as sub', 'sub.id', '=', 'cs.subject_id')
            ->where('s.class_id', $class->id)
            ->whereIn('r.enrollment_id', $enrollmentIds)
            ->whereBetween('s.date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->when($lesson, fn ($query) => $query->whereNotNull('s.class_subject_id'), fn ($query) => $query->whereNull('s.class_subject_id'))
            ->orderBy('s.date')
            ->orderBy('s.start_time')
            ->get(['s.date', 's.start_time', 's.end_time', 'r.status', 'sub.name as subject']);

        $totals = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
        $days = [];

        foreach ($rows as $row) {
            $status = AttendanceStatus::tryFrom((string) $row->status);

            if ($status === null) {
                continue;
            }

            $totals[$status->value]++;
            $days[] = [
                'date' => substr((string) $row->date, 0, 10),
                'status' => $status->value,
                'label' => $status->label(),
                'subject' => $row->subject === null ? null : (string) $row->subject,
                'time' => $row->start_time === null ? null : substr((string) $row->start_time, 0, 5).'–'.substr((string) $row->end_time, 0, 5),
            ];
        }

        return ['kind' => $lesson ? 'pertemuan' : 'hari', 'totals' => $totals, 'days' => $days];
    }
}
