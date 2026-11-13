<?php

namespace Modules\HR\Application\Performance;

use Illuminate\Support\Facades\DB;
use Modules\HR\Domain\Performance\PerformanceGroup;
use Modules\HR\Domain\Performance\Predicate;
use stdClass;

/**
 * Rekap penilaian kinerja per periode (PRD-001 §19.2–§19.3, HR-8b-2) dan hasil untuk pegawai sendiri (Kinerja saya).
 *
 * - Ringkasan per lembaga: pegawai aktif dengan penempatan utama di lembaga itu (saat ini), jumlah yang sudah dinilai
 *   di lembaga itu, rata-rata nilai akhir, dan sebaran predikat.
 * - Rincian per pegawai yang dinilai, tanpa catatan kekuatan/saran.
 * - Kinerja saya: hanya periode yang sudah dikunci (OD-HR-17).
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type AssessmentRow from PerformanceSheet
 *
 * @phpstan-type SummaryRow array{organization_id: string, organization: string, active: int, assessed: int, average: float|null, predicates: array<string, int>}
 * @phpstan-type DetailRow array{employee_name: string, employee_number: string|null, organization: string, group_label: string, scores: string, final_score: float, predicate_label: string, assessed_by: string|null, assessed_on: string}
 * @phpstan-type MyRow array{period: string, start_date: string, end_date: string, assessment: AssessmentRow}
 */
final class PerformanceRecap
{
    /**
     * @return list<SummaryRow>
     */
    public function summary(string $tenantId, string $periodId): array
    {
        $rows = [];
        $empty = array_fill_keys(array_map(fn (Predicate $predicate): string => $predicate->value, Predicate::cases()), 0);

        foreach (DB::table('hr_employees as e')
            ->join('hr_placements as p', 'p.employee_id', '=', 'e.id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', 'AKTIF')
            ->where('p.is_primary', true)
            ->whereNull('p.end_date')
            ->groupBy('p.organization_id')
            ->selectRaw('p.organization_id, count(*) AS total')
            ->get() as $row) {
            $rows[(string) $row->organization_id] = ['active' => (int) $row->total, 'assessed' => 0, 'sum' => 0.0, 'predicates' => $empty];
        }

        foreach (DB::table('hr_performance_assessments')
            ->where('tenant_id', $tenantId)
            ->where('period_id', $periodId)
            ->get(['organization_id', 'final_score', 'predicate']) as $assessment) {
            $id = (string) $assessment->organization_id;
            $rows[$id] ??= ['active' => 0, 'assessed' => 0, 'sum' => 0.0, 'predicates' => $empty];
            $rows[$id]['assessed']++;
            $rows[$id]['sum'] += (float) $assessment->final_score;
            $predicate = (string) $assessment->predicate;
            $rows[$id]['predicates'][$predicate] = ($rows[$id]['predicates'][$predicate] ?? 0) + 1;
        }

        if ($rows === []) {
            return [];
        }

        $summary = [];

        foreach (DB::table('organizations')->whereIn('id', array_map('strval', array_keys($rows)))->orderBy('code')->get(['id', 'name']) as $organization) {
            $row = $rows[(string) $organization->id];
            $summary[] = [
                'organization_id' => (string) $organization->id,
                'organization' => (string) $organization->name,
                'active' => $row['active'],
                'assessed' => $row['assessed'],
                'average' => $row['assessed'] > 0 ? round($row['sum'] / $row['assessed'], 1) : null,
                'predicates' => $row['predicates'],
            ];
        }

        return $summary;
    }

    /**
     * @return list<DetailRow>
     */
    public function details(string $tenantId, string $periodId): array
    {
        $details = [];

        foreach (DB::table('hr_performance_assessments as a')
            ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as ps', 'ps.id', '=', 'm.person_id')
            ->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->leftJoin('memberships as am', 'am.id', '=', 'a.assessed_by_membership_id')
            ->leftJoin('persons as ap', 'ap.id', '=', 'am.person_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.period_id', $periodId)
            ->orderBy('o.code')
            ->orderBy('ps.name')
            ->get(['a.*', 'ps.name as employee_name', 'e.employee_number', 'o.name as organization', 'ap.name as assessor']) as $row) {
            $assessment = PerformanceSheet::assessment($row);
            $details[] = [
                'employee_name' => (string) $row->employee_name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'organization' => (string) $row->organization,
                'group_label' => PerformanceGroup::tryFrom($assessment['group'])?->label() ?? $assessment['group'],
                'scores' => implode('; ', array_map(fn (array $score): string => "{$score['name']} {$score['score']} ({$score['weight']}%)", $assessment['scores'])),
                'final_score' => $assessment['final_score'],
                'predicate_label' => $assessment['predicate_label'],
                'assessed_by' => $assessment['assessed_by'],
                'assessed_on' => $assessment['assessed_on'],
            ];
        }

        return $details;
    }

    /**
     * Hasil penilaian pegawai sendiri di periode yang sudah dikunci, terbaru dulu.
     *
     * @return list<MyRow>
     */
    public function forEmployee(string $tenantId, string $employeeId): array
    {
        $rows = [];

        foreach (DB::table('hr_performance_assessments as a')
            ->join('hr_performance_periods as pp', 'pp.id', '=', 'a.period_id')
            ->leftJoin('memberships as am', 'am.id', '=', 'a.assessed_by_membership_id')
            ->leftJoin('persons as ap', 'ap.id', '=', 'am.person_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.employee_id', $employeeId)
            ->whereNotNull('pp.locked_at')
            ->orderByDesc('pp.start_date')
            ->get(['a.*', 'pp.name as period_name', 'pp.start_date as period_start', 'pp.end_date as period_end', 'ap.name as assessor']) as $row) {
            $rows[] = self::myRow($row);
        }

        return $rows;
    }

    /**
     * @return MyRow
     */
    private static function myRow(stdClass $row): array
    {
        return [
            'period' => (string) $row->period_name,
            'start_date' => substr((string) $row->period_start, 0, 10),
            'end_date' => substr((string) $row->period_end, 0, 10),
            'assessment' => PerformanceSheet::assessment($row),
        ];
    }
}
