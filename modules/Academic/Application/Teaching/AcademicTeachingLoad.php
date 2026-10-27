<?php

namespace Modules\Academic\Application\Teaching;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\HR\Contracts\TeachingLoad;
use stdClass;

/**
 * Beban mengajar untuk honor JP HR (PRD-001 §16.2; PRD-002 §16 jadwal, §20 presensi mengajar). Hanya membaca.
 *
 * - weekly: baris jadwal kelas aktif di tahun ajaran yang memuat tanggal itu; pengampu = guru mapel kelas.
 * - taught: catatan presensi mengajar — Mengajar (pengampu) atau Digantikan (pengganti) — di rentang tanggal.
 */
final class AcademicTeachingLoad implements TeachingLoad
{
    public function weekly(string $tenantId, array $employeeIds, string $date): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_classes as c', 'c.id', '=', 'e.class_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'e.academic_year_id')
            ->where('e.tenant_id', $tenantId)
            ->whereIn('cs.teacher_employee_id', $employeeIds)
            ->where('c.is_active', true)
            ->where('y.start_date', '<=', $date)
            ->where('y.end_date', '>=', $date)
            ->get(['cs.teacher_employee_id as employee_id', 'c.organization_id', 'e.start_time', 'e.end_time']);

        return $this->group($rows->all());
    }

    public function taught(string $tenantId, array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === [] || $end < $start) {
            return [];
        }

        $base = fn (): Builder => DB::table('ac_teaching_records as r')
            ->where('r.tenant_id', $tenantId)
            ->whereBetween('r.date', [$start, $end]);

        $taught = $base()
            ->where('r.status', TeachingStatus::Taught->value)
            ->whereIn('r.teacher_employee_id', $employeeIds)
            ->get(['r.teacher_employee_id as employee_id', 'r.organization_id', 'r.start_time', 'r.end_time']);

        $substituting = $base()
            ->where('r.status', TeachingStatus::Substituted->value)
            ->whereIn('r.substitute_employee_id', $employeeIds)
            ->get(['r.substitute_employee_id as employee_id', 'r.organization_id', 'r.start_time', 'r.end_time']);

        return $this->group([...$taught->all(), ...$substituting->all()]);
    }

    /**
     * @param  array<int, stdClass>  $rows
     * @return array<string, array<string, list<int>>>
     */
    private function group(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $minutes = intdiv(self::seconds((string) $row->end_time) - self::seconds((string) $row->start_time), 60);

            if ($minutes > 0) {
                $result[(string) $row->employee_id][(string) $row->organization_id][] = $minutes;
            }
        }

        return $result;
    }

    private static function seconds(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time) + [0, 0]);

        return $hour * 3600 + $minute * 60;
    }
}
