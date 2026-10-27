<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Employee\EducationLevel;

/**
 * Laporan jumlah pegawai (PRD-001 §17, HR-6a; [LAMA] FR-041). Cakupan sama dengan daftar Pegawai
 * (EmployeeVisibility, §5.3) — Kepala Lembaga hanya melihat angka lembaganya. Hanya membaca.
 *
 * Pegawai aktif dikelompokkan per lembaga (penempatan utama), jenis pegawai & status kepegawaian
 * (periode terakhir), jabatan (per penempatan berjalan — rangkap jabatan terhitung di tiap jabatan),
 * jenis kelamin, dan pendidikan terakhir. Ditambah jumlah pegawai yang berhenti tahun ini.
 *
 * @phpstan-type Group array{label: string, count: int}
 * @phpstan-type Report array{total: int, ended_this_year: int, year: int, groups: array{organization: list<Group>, employment_type: list<Group>, classification: list<Group>, position: list<Group>, gender: list<Group>, education: list<Group>}}
 */
final class EmployeeReport
{
    public const GROUP_TITLES = [
        'organization' => 'Lembaga (penempatan utama)',
        'employment_type' => 'Jenis pegawai',
        'classification' => 'Status kepegawaian',
        'position' => 'Jabatan',
        'gender' => 'Jenis kelamin',
        'education' => 'Pendidikan terakhir',
    ];

    public function __construct(
        private readonly EmployeeDirectory $directory,
    ) {}

    /**
     * @return Report
     */
    public function build(?string $organizationId): array
    {
        $active = $this->directory->ids(new EmployeeFilters(status: EmployeeFilters::STATUS_ACTIVE, organizationId: $organizationId));
        $ended = $this->directory->ids(new EmployeeFilters(status: EmployeeFilters::STATUS_ENDED, organizationId: $organizationId));
        $year = (int) BusinessClock::today()->format('Y');

        $endedThisYear = $ended === [] ? 0 : DB::table('hr_employment_periods')
            ->whereIn('employee_id', $ended)
            ->whereNotNull('end_date')
            ->whereYear('end_date', $year)
            ->distinct()
            ->count('employee_id');

        return [
            'total' => count($active),
            'ended_this_year' => $endedThisYear,
            'year' => $year,
            'groups' => [
                'organization' => $this->organizations($active),
                'employment_type' => $this->periods($active, 'hr_employment_types', 'employment_type_id'),
                'classification' => $this->periods($active, 'hr_employment_classifications', 'classification_id'),
                'position' => $this->positions($active),
                'gender' => $this->personField($active, 'p.gender', fn (string $value): string => Gender::from($value)->label()),
                'education' => $this->personField($active, 'e.education_level', fn (string $value): string => EducationLevel::from($value)->label()),
            ],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{label: string, count: int}>
     */
    private function organizations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = DB::table('hr_employees as e')
            ->leftJoin('hr_placements as pl', fn ($join) => $join->on('pl.employee_id', '=', 'e.id')->where('pl.is_primary', true)->whereNull('pl.end_date'))
            ->leftJoin('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->whereIn('e.id', $ids)
            ->groupBy('o.name')
            ->selectRaw('o.name AS label, COUNT(*) AS total')
            ->get();

        return $this->sorted($counts->map(fn (object $row): array => ['label' => $row->label === null ? 'Tanpa penempatan utama' : (string) $row->label, 'count' => (int) $row->total])->all());
    }

    /**
     * Jenis pegawai / status kepegawaian dari periode terakhir (berjalan) tiap pegawai.
     *
     * @param  list<string>  $ids
     * @return list<array{label: string, count: int}>
     */
    private function periods(array $ids, string $table, string $column): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('hr_employment_periods as pe')
            ->leftJoin("{$table} as c", 'c.id', '=', "pe.{$column}")
            ->whereIn('pe.employee_id', $ids)
            ->orderBy('pe.start_date')
            ->get(['pe.employee_id', 'c.name']);

        // Urut tanggal mulai: baris terakhir per pegawai = periode terakhir.
        $latest = [];

        foreach ($rows as $row) {
            $latest[(string) $row->employee_id] = $row->name === null ? '(kosong)' : (string) $row->name;
        }

        $counts = array_count_values($latest);

        return $this->sorted(array_map(fn (string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($counts), array_values($counts)));
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{label: string, count: int}>
     */
    private function positions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = DB::table('hr_placements as pl')
            ->join('hr_positions as po', 'po.id', '=', 'pl.position_id')
            ->whereIn('pl.employee_id', $ids)
            ->whereNull('pl.end_date')
            ->groupBy('po.name')
            ->selectRaw('po.name AS label, COUNT(*) AS total')
            ->get();

        return $this->sorted($counts->map(fn (object $row): array => ['label' => (string) $row->label, 'count' => (int) $row->total])->all());
    }

    /**
     * @param  list<string>  $ids
     * @param  literal-string  $column  kolom tetap di kode (bukan isian pengguna), aman untuk selectRaw
     * @param  callable(string): string  $label
     * @return list<array{label: string, count: int}>
     */
    private function personField(array $ids, string $column, callable $label): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->whereIn('e.id', $ids)
            ->groupBy($column)
            ->selectRaw("{$column} AS value, COUNT(*) AS total")
            ->get();

        return $this->sorted($counts->map(fn (object $row): array => ['label' => $row->value === null ? '(belum diisi)' : $label((string) $row->value), 'count' => (int) $row->total])->all());
    }

    /**
     * Terbanyak dulu, lalu nama.
     *
     * @param  array<int, array{label: string, count: int}>  $groups
     * @return list<array{label: string, count: int}>
     */
    private function sorted(array $groups): array
    {
        usort($groups, fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return $groups;
    }
}
