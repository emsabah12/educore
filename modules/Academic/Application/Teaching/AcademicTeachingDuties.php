<?php

namespace Modules\Academic\Application\Teaching;

use Illuminate\Support\Facades\DB;
use Modules\HR\Contracts\TeachingDuties;

/**
 * Tugas wali kelas & guru mapel yang masih tercatat untuk seorang pegawai (PRD-001 §18.2, HR-7b): kelas aktif di
 * tahun ajaran yang belum berakhir pada tanggal itu. Dipakai checklist offboarding sebagai pengingat.
 */
final class AcademicTeachingDuties implements TeachingDuties
{
    public function forEmployee(string $tenantId, string $employeeId, string $date): array
    {
        $duties = [];

        foreach (DB::table('ac_classes as c')
            ->join('ac_academic_years as y', 'y.id', '=', 'c.academic_year_id')
            ->join('organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('c.tenant_id', $tenantId)
            ->where('c.homeroom_employee_id', $employeeId)
            ->where('c.is_active', true)
            ->where('y.end_date', '>=', $date)
            ->orderBy('o.name')
            ->orderBy('c.name')
            ->get(['c.name', 'o.name as organization']) as $row) {
            $duties[] = "Wali kelas {$row->name} ({$row->organization})";
        }

        foreach (DB::table('ac_class_subjects as cs')
            ->join('ac_classes as c', 'c.id', '=', 'cs.class_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'c.academic_year_id')
            ->join('organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('cs.tenant_id', $tenantId)
            ->where('cs.teacher_employee_id', $employeeId)
            ->where('c.is_active', true)
            ->where('y.end_date', '>=', $date)
            ->groupBy('o.name')
            ->orderBy('o.name')
            ->get(['o.name as organization', DB::raw('count(DISTINCT cs.class_id) AS classes'), DB::raw('count(*) AS subjects')]) as $row) {
            $duties[] = "Guru {$row->subjects} mapel di {$row->classes} kelas ({$row->organization})";
        }

        return $duties;
    }
}
