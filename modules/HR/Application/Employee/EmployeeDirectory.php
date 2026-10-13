<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Domain\Employee\EmployeeStatus;
use stdClass;

/**
 * Daftar pegawai dengan pencarian & saringan (PRD-001 §6). Semua saringan, termasuk hak lihat
 * (EmployeeVisibility), dijalankan di database sebelum paginasi.
 */
final class EmployeeDirectory
{
    public const DEFAULT_PER_PAGE = 25;

    /** PRD-000 §9: maksimal 100 baris per halaman. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly EmployeeVisibility $visibility,
    ) {}

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function search(EmployeeFilters $filters): LengthAwarePaginator
    {
        $query = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id');

        $this->visibility->apply($query, HrAccess::EMPLOYEES_VIEW);
        $this->applyFilters($query, $filters);

        /** @var LengthAwarePaginator<int, stdClass> $page */
        $page = $query
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->select(['e.id', 'e.employee_number', 'e.status', 'p.name'])
            ->paginate($filters->perPage);

        return $page->withQueryString();
    }

    /**
     * Ringkasan per baris: jenis & status kepegawaian dari periode terakhir, penempatan berjalan.
     *
     * @param  list<stdClass>  $rows  baris dari search()
     * @return list<array{id: string, name: string, employee_number: string|null, status: string, status_label: string, employment_type: string|null, classification: string|null, primary_placement: string|null, other_placements: int}>
     */
    public function present(array $rows): array
    {
        $ids = array_map(fn (stdClass $row): string => (string) $row->id, $rows);

        if ($ids === []) {
            return [];
        }

        $latestPeriods = $this->latestPeriods($ids);
        $placements = $this->currentPlacements($ids, $latestPeriods);

        $result = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $status = EmployeeStatus::from((string) $row->status);
            $period = $latestPeriods[$id] ?? null;
            $current = $placements[$id] ?? [];
            $primary = $current[0] ?? null;

            $result[] = [
                'id' => $id,
                'name' => (string) $row->name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'status' => $status->value,
                'status_label' => $status->label(),
                'employment_type' => $period['type'] ?? null,
                'classification' => $period['classification'] ?? null,
                'primary_placement' => $primary,
                'other_placements' => max(0, count($current) - 1),
            ];
        }

        return $result;
    }

    private function applyFilters(Builder $query, EmployeeFilters $filters): void
    {
        if ($filters->status !== EmployeeFilters::STATUS_ALL) {
            $query->where('e.status', $filters->status === EmployeeFilters::STATUS_ENDED ? EmployeeStatus::Ended->value : EmployeeStatus::Active->value);
        }

        if ($filters->search !== '') {
            $pattern = '%'.addcslashes($filters->search, '%_\\').'%';

            $query->where(function (Builder $search) use ($pattern): void {
                $search->where('p.name', 'ilike', $pattern)
                    ->orWhere('e.employee_number', 'ilike', $pattern);
            });
        }

        // Lembaga: penempatan berjalan di node itu atau turunannya (mis. Unit 1 → MTs Unit 1).
        if ($filters->organizationId !== null || $filters->positionId !== null) {
            $query->whereExists(function (Builder $placements) use ($filters): void {
                $placements->select(DB::raw(1))
                    ->from('hr_placements as fp')
                    ->whereColumn('fp.employee_id', 'e.id')
                    ->whereRaw(EmployeeVisibility::currentPlacementSql('fp'));

                if ($filters->organizationId !== null) {
                    $placements->whereIn('fp.organization_id', function (Builder $closure) use ($filters): void {
                        $closure->select('descendant_id')
                            ->from('organization_closure')
                            ->where('ancestor_id', $filters->organizationId);
                    });
                }

                if ($filters->positionId !== null) {
                    $placements->where('fp.position_id', $filters->positionId);
                }
            });
        }

        // Jenis & status kepegawaian: dari periode terakhir pegawai.
        if ($filters->employmentTypeId !== null || $filters->classificationId !== null) {
            $query->whereExists(function (Builder $periods) use ($filters): void {
                $periods->select(DB::raw(1))
                    ->from('hr_employment_periods as fe')
                    ->whereColumn('fe.employee_id', 'e.id')
                    ->whereRaw('fe.start_date = (SELECT max(lp2.start_date) FROM hr_employment_periods lp2 WHERE lp2.employee_id = e.id)');

                if ($filters->employmentTypeId !== null) {
                    $periods->where('fe.employment_type_id', $filters->employmentTypeId);
                }

                if ($filters->classificationId !== null) {
                    $periods->where('fe.classification_id', $filters->classificationId);
                }
            });
        }
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array{type: string, classification: string|null, end_date: string|null}>
     */
    private function latestPeriods(array $employeeIds): array
    {
        $rows = DB::table('hr_employment_periods as ep')
            ->join('hr_employment_types as t', 't.id', '=', 'ep.employment_type_id')
            ->leftJoin('hr_employment_classifications as c', 'c.id', '=', 'ep.classification_id')
            ->whereIn('ep.employee_id', $employeeIds)
            ->orderBy('ep.start_date')
            ->get(['ep.employee_id', 'ep.end_date', 't.name as type_name', 'c.name as classification_name']);

        $latest = [];

        // Urut tanggal mulai: baris terakhir per pegawai = periode terakhir.
        foreach ($rows as $row) {
            $latest[(string) $row->employee_id] = [
                'type' => (string) $row->type_name,
                'classification' => $row->classification_name === null ? null : (string) $row->classification_name,
                'end_date' => $row->end_date === null ? null : (string) $row->end_date,
            ];
        }

        return $latest;
    }

    /**
     * Penempatan berjalan per pegawai, yang utama di depan. Teks: "Jabatan · Lembaga".
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, array{type: string, classification: string|null, end_date: string|null}>  $latestPeriods
     * @return array<string, list<string>>
     */
    private function currentPlacements(array $employeeIds, array $latestPeriods): array
    {
        $rows = DB::table('hr_placements as pl')
            ->join('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->join('hr_positions as pos', 'pos.id', '=', 'pl.position_id')
            ->whereIn('pl.employee_id', $employeeIds)
            ->orderByDesc('pl.is_primary')
            ->orderBy('pl.start_date')
            ->get(['pl.employee_id', 'pl.end_date', 'o.name as organization_name', 'pos.name as position_name']);

        $current = [];

        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;
            $endDate = $row->end_date === null ? null : (string) $row->end_date;
            $lastPeriodEnd = $latestPeriods[$employeeId]['end_date'] ?? null;

            // Sama dengan EmployeeVisibility::currentPlacementSql(): terbuka, atau ditutup saat pegawai berhenti.
            $isCurrent = $endDate === null || ($lastPeriodEnd !== null && $endDate === $lastPeriodEnd);

            if ($isCurrent) {
                $current[$employeeId][] = "{$row->position_name} · {$row->organization_name}";
            }
        }

        return $current;
    }
}
