<?php

namespace Modules\HR\Application\Performance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\TeachingLoad;
use Modules\HR\Domain\Performance\PerformanceGroup;
use Modules\HR\Domain\Performance\Predicate;
use stdClass;

/**
 * Tampilan penilaian kinerja (PRD-001 §19.2, HR-8b): daftar periode dan lembar penilaian per periode × lembaga.
 *
 * Isi lembar = pegawai AKTIF yang penempatan utamanya (masih berjalan) di lembaga itu, ditambah pegawai yang sudah
 * dinilai di lembaga itu pada periode tersebut tetapi sudah pindah (tampil, tidak bisa dinilai ulang dari sini).
 * Kelompok usulan: Guru bila punya jadwal mengajar yang berlaku (kontrak `TeachingLoad`), selain itu Staf.
 * Hak akses dicek pemanggil.
 *
 * @phpstan-type PeriodRow array{id: string, name: string, start_date: string, end_date: string, locked: bool, started: bool, assessed: int}
 * @phpstan-type ScoreRow array{name: string, weight: int, score: int}
 * @phpstan-type AssessmentRow array{group: string, scores: list<ScoreRow>, final_score: float, predicate: string, predicate_label: string, strengths: string|null, suggestions: string|null, assessed_by: string|null, assessed_on: string}
 * @phpstan-type SheetRow array{employee_id: string, name: string, employee_number: string|null, positions: list<string>, suggested_group: string, current: bool, is_self: bool, assessment: AssessmentRow|null}
 */
final class PerformanceSheet
{
    public function __construct(private readonly TeachingLoad $teaching) {}

    /**
     * Periode terbaru (tanggal mulai) dulu.
     *
     * @return list<PeriodRow>
     */
    public function periods(string $tenantId): array
    {
        $today = BusinessClock::todayString();
        $counts = DB::table('hr_performance_assessments')
            ->where('tenant_id', $tenantId)
            ->groupBy('period_id')
            ->selectRaw('period_id, count(*) AS total')
            ->pluck('total', 'period_id');
        $rows = [];

        foreach (DB::table('hr_performance_periods')->where('tenant_id', $tenantId)->orderByDesc('start_date')->orderBy('name')->get() as $period) {
            $start = substr((string) $period->start_date, 0, 10);
            $rows[] = [
                'id' => (string) $period->id,
                'name' => (string) $period->name,
                'start_date' => $start,
                'end_date' => substr((string) $period->end_date, 0, 10),
                'locked' => $period->locked_at !== null,
                'started' => $start <= $today,
                'assessed' => (int) ($counts[(string) $period->id] ?? 0),
            ];
        }

        return $rows;
    }

    public function period(string $tenantId, string $periodId): ?stdClass
    {
        return Str::isUuid($periodId)
            ? DB::table('hr_performance_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->first()
            : null;
    }

    /**
     * Lembaga penempatan utama (berjalan) pegawai AKTIF; null bila tidak aktif / tanpa penempatan utama.
     */
    public function primaryOrganization(string $tenantId, string $employeeId): ?string
    {
        if (! Str::isUuid($employeeId)) {
            return null;
        }

        $organization = DB::table('hr_employees as e')
            ->join('hr_placements as p', 'p.employee_id', '=', 'e.id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.id', $employeeId)
            ->where('e.status', 'AKTIF')
            ->where('p.is_primary', true)
            ->whereNull('p.end_date')
            ->value('p.organization_id');

        return $organization === null ? null : (string) $organization;
    }

    /**
     * Kelompok usulan per pegawai: Guru bila punya jadwal mengajar yang berlaku pada akhir periode (atau hari ini bila
     * periode masih berjalan).
     *
     * @param  list<string>  $employeeIds
     * @return array<string, PerformanceGroup>
     */
    public function suggestedGroups(string $tenantId, stdClass $period, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $today = BusinessClock::todayString();
        $start = substr((string) $period->start_date, 0, 10);
        $end = substr((string) $period->end_date, 0, 10);
        $date = min($end, max($start, $today));
        $teaching = $this->teaching->weekly($tenantId, $employeeIds, $date);
        $groups = [];

        foreach ($employeeIds as $id) {
            $groups[$id] = ($teaching[$id] ?? []) !== [] ? PerformanceGroup::Teacher : PerformanceGroup::Staff;
        }

        return $groups;
    }

    /**
     * @return list<SheetRow>
     */
    public function rows(string $tenantId, stdClass $period, string $organizationId, ?string $selfEmployeeId): array
    {
        $people = [];

        // Pegawai aktif dengan penempatan utama berjalan di lembaga ini.
        foreach (DB::table('hr_employees as e')
            ->join('hr_placements as p', 'p.employee_id', '=', 'e.id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as ps', 'ps.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', 'AKTIF')
            ->where('p.organization_id', $organizationId)
            ->where('p.is_primary', true)
            ->whereNull('p.end_date')
            ->orderBy('ps.name')
            ->get(['e.id', 'ps.name', 'e.employee_number']) as $row) {
            $people[(string) $row->id] = ['name' => (string) $row->name, 'number' => $row->employee_number === null ? null : (string) $row->employee_number, 'current' => true];
        }

        // Sudah dinilai di lembaga ini tetapi penempatan utamanya sudah pindah / berhenti.
        $assessments = [];

        foreach (DB::table('hr_performance_assessments as a')
            ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as ps', 'ps.id', '=', 'm.person_id')
            ->leftJoin('memberships as am', 'am.id', '=', 'a.assessed_by_membership_id')
            ->leftJoin('persons as ap', 'ap.id', '=', 'am.person_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.period_id', $period->id)
            ->where(fn ($query) => $query->where('a.organization_id', $organizationId)->orWhereIn('a.employee_id', array_map('strval', array_keys($people))))
            ->get(['a.*', 'ps.name as employee_name', 'e.employee_number', 'ap.name as assessor']) as $row) {
            $id = (string) $row->employee_id;
            $assessments[$id] = $row;

            if (! isset($people[$id]) && (string) $row->organization_id === $organizationId) {
                $people[$id] = ['name' => (string) $row->employee_name, 'number' => $row->employee_number === null ? null : (string) $row->employee_number, 'current' => false];
            }
        }

        $ids = array_map('strval', array_keys($people));
        $positions = $this->positions($ids, $organizationId);
        $groups = $this->suggestedGroups($tenantId, $period, $ids);
        $rows = [];

        foreach ($people as $id => $person) {
            $id = (string) $id;
            $rows[] = [
                'employee_id' => $id,
                'name' => $person['name'],
                'employee_number' => $person['number'],
                'positions' => $positions[$id] ?? [],
                'suggested_group' => ($groups[$id] ?? PerformanceGroup::Staff)->value,
                'current' => $person['current'],
                'is_self' => $id === $selfEmployeeId,
                'assessment' => isset($assessments[$id]) ? self::assessment($assessments[$id]) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [! $a['current'], mb_strtolower($a['name'])] <=> [! $b['current'], mb_strtolower($b['name'])]);

        return $rows;
    }

    /**
     * @return AssessmentRow
     */
    public static function assessment(stdClass $row): array
    {
        $scores = [];

        foreach ((array) json_decode((string) $row->scores, true) as $score) {
            if (is_array($score)) {
                $scores[] = ['name' => (string) ($score['name'] ?? ''), 'weight' => (int) ($score['weight'] ?? 0), 'score' => (int) ($score['score'] ?? 0)];
            }
        }

        $predicate = Predicate::tryFrom((string) $row->predicate) ?? Predicate::fromScore((float) $row->final_score);

        return [
            'group' => (string) $row->group,
            'scores' => $scores,
            'final_score' => (float) $row->final_score,
            'predicate' => $predicate->value,
            'predicate_label' => $predicate->label(),
            'strengths' => $row->strengths === null ? null : (string) $row->strengths,
            'suggestions' => $row->suggestions === null ? null : (string) $row->suggestions,
            'assessed_by' => isset($row->assessor) ? (string) $row->assessor : null,
            'assessed_on' => CarbonImmutable::parse((string) $row->updated_at, 'UTC')->setTimezone(BusinessClock::timezone())->toDateString(),
        ];
    }

    /**
     * Jabatan berjalan pegawai di lembaga ini.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<string>>
     */
    private function positions(array $employeeIds, string $organizationId): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $positions = [];

        foreach (DB::table('hr_placements as p')
            ->join('hr_positions as j', 'j.id', '=', 'p.position_id')
            ->whereIn('p.employee_id', $employeeIds)
            ->where('p.organization_id', $organizationId)
            ->whereNull('p.end_date')
            ->orderByDesc('p.is_primary')
            ->orderBy('j.name')
            ->get(['p.employee_id', 'j.name']) as $row) {
            $positions[(string) $row->employee_id][] = (string) $row->name;
        }

        return $positions;
    }
}
