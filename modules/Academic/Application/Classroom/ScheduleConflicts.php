<?php

namespace Modules\Academic\Application\Classroom;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\HR\Contracts\ActiveEmployees;
use stdClass;

/**
 * Pemeriksaan bentrok jadwal (PRD-002 §16 AC-3c) [OWNER, 2026-10-21]:
 * - Bentrok kelas: kelas yang sama punya dua baris yang jamnya beririsan di hari yang sama.
 * - Bentrok guru: guru pengampu yang sama mengajar di dua tempat yang jamnya beririsan di hari yang
 *   sama, di seluruh yayasan, pada tahun ajaran yang tanggalnya beririsan.
 *
 * Jam "beririsan" = mulai A < selesai B dan mulai B < selesai A (07.00–08.00 lalu 08.00–09.00 tidak bentrok).
 * Pemanggil wajib di dalam transaksi dan sudah mengunci kelas (bentrok kelas) serta memanggil
 * lockTeacher() (bentrok guru) agar dua penyimpanan bersamaan tidak lolos.
 */
final class ScheduleConflicts
{
    public function __construct(
        private readonly ActiveEmployees $employees,
    ) {}

    /**
     * Kunci per guru sampai transaksi selesai.
     */
    public function lockTeacher(string $teacherEmployeeId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['ac-teacher-schedule:'.$teacherEmployeeId]);
    }

    /**
     * @return string|null pesan bentrok, atau null bila aman
     */
    public function classClash(string $classId, int $day, string $start, string $end, ?string $exceptEntryId): ?string
    {
        $query = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('e.class_id', $classId)
            ->where('e.day_of_week', $day)
            ->where('e.start_time', '<', $end)
            ->where('e.end_time', '>', $start)
            ->orderBy('e.start_time');

        if ($exceptEntryId !== null) {
            $query->where('e.id', '<>', $exceptEntryId);
        }

        $clash = $query->first(['e.day_of_week', 'e.start_time', 'e.end_time', 's.name']);

        if ($clash === null) {
            return null;
        }

        return 'Bentrok dengan jadwal kelas ini: '.$this->slot($clash).' '.(string) $clash->name.'.';
    }

    /**
     * @return string|null pesan bentrok, atau null bila aman
     */
    public function teacherClash(
        string $tenantId,
        string $teacherEmployeeId,
        AcademicYear $year,
        int $day,
        string $start,
        string $end,
        ?string $exceptEntryId,
        ?string $exceptClassSubjectId,
    ): ?string {
        $query = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->join('ac_classes as c', 'c.id', '=', 'e.class_id')
            ->join('organizations as o', 'o.id', '=', 'c.organization_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'e.academic_year_id')
            ->where('e.tenant_id', $tenantId)
            ->where('cs.teacher_employee_id', $teacherEmployeeId)
            ->where('e.day_of_week', $day)
            ->where('e.start_time', '<', $end)
            ->where('e.end_time', '>', $start)
            ->where('y.start_date', '<=', $year->end_date->toDateString())
            ->where('y.end_date', '>=', $year->start_date->toDateString())
            ->orderBy('e.start_time');

        if ($exceptEntryId !== null) {
            $query->where('e.id', '<>', $exceptEntryId);
        }

        if ($exceptClassSubjectId !== null) {
            $query->where('e.class_subject_id', '<>', $exceptClassSubjectId);
        }

        $clash = $query->first(['e.day_of_week', 'e.start_time', 'e.end_time', 's.name', 'c.name as class_name', 'o.name as organization_name']);

        if ($clash === null) {
            return null;
        }

        $teacher = $this->employees->describe($tenantId, [$teacherEmployeeId])[$teacherEmployeeId]['name'] ?? 'pengampu';

        return "Bentrok jadwal guru {$teacher}: ".$this->slot($clash).' '.(string) $clash->name.' di kelas '.(string) $clash->class_name.' ('.(string) $clash->organization_name.').';
    }

    /**
     * "Senin 07.00–08.20".
     */
    private function slot(stdClass $row): string
    {
        return ScheduleEntry::dayName((int) $row->day_of_week).' '
            .ScheduleEntry::displayTime((string) $row->start_time).'–'
            .ScheduleEntry::displayTime((string) $row->end_time);
    }
}
