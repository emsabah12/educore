<?php

namespace Modules\HR\Application\Leave;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HR\Domain\Employee\EmployeeStatus;
use stdClass;

/**
 * Pilih pegawai untuk "Catat cuti atas nama" (PRD-001 §13.5, HR-3d): hanya pegawai aktif dengan
 * penempatan utama terbuka (syarat pengajuan cuti). Hak akses dicek pemanggil.
 *
 * @phpstan-type Candidate array{id: string, name: string, employee_number: string|null, organization: string}
 */
final class LeaveEmployeePicker
{
    public const MIN_SEARCH_LENGTH = 2;

    public const MAX_RESULTS = 10;

    /**
     * Cari berdasarkan nama atau nomor induk; kurang dari 2 huruf → kosong.
     *
     * @return list<Candidate>
     */
    public function search(string $tenantId, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            return [];
        }

        $pattern = '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';

        $rows = $this->base($tenantId)
            ->where(fn (Builder $query) => $query->where('p.name', 'ilike', $pattern)->orWhere('e.employee_number', 'ilike', $pattern))
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->limit(self::MAX_RESULTS)
            ->get(['e.id', 'e.employee_number', 'p.name', 'o.name as organization']);

        $result = [];

        foreach ($rows as $row) {
            $result[] = $this->present($row);
        }

        return $result;
    }

    /**
     * @return Candidate|null pegawai yang memenuhi syarat, atau null
     */
    public function find(string $tenantId, string $employeeId): ?array
    {
        if (! Str::isUuid($employeeId)) {
            return null;
        }

        $row = $this->base($tenantId)->where('e.id', $employeeId)->first(['e.id', 'e.employee_number', 'p.name', 'o.name as organization']);

        return $row instanceof stdClass ? $this->present($row) : null;
    }

    private function base(string $tenantId): Builder
    {
        return DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('hr_placements as pl', fn (JoinClause $join) => $join
                ->on('pl.employee_id', '=', 'e.id')
                ->where('pl.is_primary', true)
                ->whereNull('pl.end_date'))
            ->join('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', EmployeeStatus::Active->value);
    }

    /**
     * @return Candidate
     */
    private function present(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
            'organization' => (string) $row->organization,
        ];
    }
}
