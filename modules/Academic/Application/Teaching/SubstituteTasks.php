<?php

namespace Modules\Academic\Application\Teaching;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Attendance\AttendanceModeSetting;
use Modules\Academic\Application\Attendance\ManageAttendance;
use Modules\Academic\Domain\Teaching\AbsenceReason;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\ActiveEmployees;
use stdClass;

/**
 * Tugas menggantikan milik seorang guru (PRD-002 §20.3, AC-6b-2): pertemuan yang tercatat
 * "Digantikan" dengan dia sebagai pengganti. Hanya membaca.
 *
 * - Kelas saya menampilkan 6 hari terakhir s.d. 7 hari ke depan [ASUMSI].
 * - Presensi siswa pertemuan itu boleh diisi pengganti pada hari-H s.d. 6 hari sesudahnya (sama
 *   dengan batas guru pengampu), hanya untuk lembaga yang memakai presensi per pertemuan.
 *
 * @phpstan-type Task array{key: string, entry_id: string|null, date: string, start: string, end: string, class_id: string, class_name: string, organization: string, subject: string, teacher: string|null, reason: string|null, lesson_mode: bool, can_fill: bool}
 */
final class SubstituteTasks
{
    public const AHEAD_DAYS = 7;

    public function __construct(
        private readonly ActiveEmployees $employees,
        private readonly AttendanceModeSetting $mode,
    ) {}

    /**
     * Ada tugas menggantikan di rentang Kelas saya (untuk menu).
     */
    public function hasAny(string $tenantId, string $employeeId): bool
    {
        [$start, $end] = $this->range();

        return $this->query($tenantId, $employeeId)->whereBetween('r.date', [$start, $end])->exists();
    }

    /**
     * @return list<Task> urut tanggal & jam
     */
    public function list(string $tenantId, string $employeeId): array
    {
        [$start, $end] = $this->range();

        $rows = $this->query($tenantId, $employeeId)
            ->whereBetween('r.date', [$start, $end])
            ->orderBy('r.date')
            ->orderBy('r.start_time')
            ->get(['r.*', 'c.name as class_name', 'o.name as organization', 's.name as subject']);

        $teacherIds = [];

        foreach ($rows as $row) {
            if ($row->teacher_employee_id !== null) {
                $teacherIds[] = (string) $row->teacher_employee_id;
            }
        }

        $names = $this->employees->describe($tenantId, array_values(array_unique($teacherIds)));
        $modes = [];
        $tasks = [];

        foreach ($rows as $row) {
            $organizationId = (string) $row->organization_id;
            $lessonMode = $modes[$organizationId] ??= $this->mode->isLesson($tenantId, $organizationId);
            $date = substr((string) $row->date, 0, 10);
            $reason = $row->absence_reason === null ? null : AbsenceReason::tryFrom((string) $row->absence_reason);
            $teacher = $row->teacher_employee_id === null ? null : ($names[(string) $row->teacher_employee_id]['name'] ?? null);

            $tasks[] = [
                'key' => (string) $row->id,
                'entry_id' => $row->schedule_entry_id === null ? null : (string) $row->schedule_entry_id,
                'date' => $date,
                'start' => substr((string) $row->start_time, 0, 5),
                'end' => substr((string) $row->end_time, 0, 5),
                'class_id' => (string) $row->class_id,
                'class_name' => (string) $row->class_name,
                'organization' => (string) $row->organization,
                'subject' => (string) $row->subject,
                'teacher' => $teacher,
                'reason' => $reason?->label(),
                'lesson_mode' => $lessonMode,
                'can_fill' => $lessonMode && $row->schedule_entry_id !== null && self::fillable($date),
            ];
        }

        return $tasks;
    }

    /**
     * Catatan "Digantikan" untuk pertemuan ini pada tanggal itu dengan pegawai ini sebagai pengganti.
     */
    public function find(string $tenantId, string $employeeId, string $entryId, string $date): ?stdClass
    {
        $row = $this->query($tenantId, $employeeId)
            ->where('r.schedule_entry_id', $entryId)
            ->where('r.date', $date)
            ->first(['r.*']);

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Pengganti boleh mengisi presensi siswa pada hari-H s.d. 6 hari sesudahnya.
     */
    public static function fillable(string $date): bool
    {
        $today = BusinessClock::today();

        return $date <= $today->toDateString()
            && $date >= $today->subDays(ManageAttendance::HOMEROOM_DAYS - 1)->toDateString();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(): array
    {
        $today = BusinessClock::today();

        return [
            $today->subDays(ManageAttendance::HOMEROOM_DAYS - 1)->toDateString(),
            $today->addDays(self::AHEAD_DAYS)->toDateString(),
        ];
    }

    private function query(string $tenantId, string $employeeId): Builder
    {
        return DB::table('ac_teaching_records as r')
            ->join('ac_classes as c', 'c.id', '=', 'r.class_id')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'r.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.status', TeachingStatus::Substituted->value)
            ->where('r.substitute_employee_id', $employeeId);
    }
}
