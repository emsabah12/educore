<?php

namespace Modules\HR\Application\Discipline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Domain\Discipline\DisciplineKind;
use stdClass;

/**
 * Tampilan catatan disiplin (PRD-001 §18.1, HR-7a): per pegawai (tab Disiplin, Dokumen saya) dan daftar seluruh
 * yayasan (halaman Disiplin pegawai). Hak akses & cakupan dicek / diterapkan pemanggil.
 *
 * Status dihitung, tidak disimpan: DICABUT · BERLAKU (berlaku sampai ≥ hari ini) · KEDALUWARSA · TERCATAT (teguran
 * tanpa masa berlaku).
 *
 * @phpstan-type RecordRow array{
 *     id: string,
 *     kind: string,
 *     kind_label: string,
 *     issued_on: string,
 *     valid_until: string|null,
 *     violation: string,
 *     follow_up: string|null,
 *     status: 'BERLAKU'|'KEDALUWARSA'|'TERCATAT'|'DICABUT',
 *     revoke_reason: string|null,
 *     revoked_on: string|null,
 *     file_name: string|null,
 *     recorded_by: string|null
 * }
 * @phpstan-type ListRow array{
 *     id: string,
 *     employee_id: string,
 *     employee_name: string,
 *     employee_number: string|null,
 *     organization: string|null,
 *     kind: string,
 *     kind_label: string,
 *     issued_on: string,
 *     valid_until: string|null,
 *     status: 'BERLAKU'|'KEDALUWARSA'|'TERCATAT'|'DICABUT'
 * }
 */
final class DisciplineRecords
{
    public const PER_PAGE = 50;

    public const FILTER_ACTIVE = 'berlaku';

    public const FILTER_ALL = 'semua';

    /**
     * Catatan seorang pegawai: yang masih berlaku dulu, lalu terbaru di atas.
     *
     * @return list<RecordRow>
     */
    public function forEmployee(string $tenantId, string $employeeId): array
    {
        $today = BusinessClock::todayString();
        $rows = [];

        foreach (DB::table('hr_discipline_records as d')
            ->leftJoin('memberships as rm', 'rm.id', '=', 'd.recorded_by_membership_id')
            ->leftJoin('persons as rp', 'rp.id', '=', 'rm.person_id')
            ->where('d.tenant_id', $tenantId)
            ->where('d.employee_id', $employeeId)
            ->orderByRaw('d.revoked_at IS NOT NULL')
            ->orderByDesc('d.issued_on')
            ->orderByDesc('d.created_at')
            ->get(['d.*', 'rp.name as recorded_by']) as $row) {
            $rows[] = [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'kind_label' => self::kindLabel((string) $row->kind),
                'issued_on' => substr((string) $row->issued_on, 0, 10),
                'valid_until' => $row->valid_until === null ? null : substr((string) $row->valid_until, 0, 10),
                'violation' => (string) $row->violation,
                'follow_up' => $row->follow_up === null ? null : (string) $row->follow_up,
                'status' => self::status($row, $today),
                'revoke_reason' => $row->revoke_reason === null ? null : (string) $row->revoke_reason,
                'revoked_on' => $row->revoked_at === null ? null : CarbonImmutable::parse((string) $row->revoked_at)->setTimezone(BusinessClock::timezone())->toDateString(),
                'file_name' => $row->original_name === null ? null : (string) $row->original_name,
                'recorded_by' => $row->recorded_by === null ? null : (string) $row->recorded_by,
            ];
        }

        return $rows;
    }

    /**
     * Daftar seluruh catatan yang terlihat (query pegawai beralias `e` sudah disaring cakupannya oleh $scope).
     *
     * @param  callable(Builder): void  $scope  penyaring cakupan lihat pegawai (EmployeeVisibility::apply)
     * @return LengthAwarePaginator<int, ListRow>
     */
    public function list(string $tenantId, callable $scope, ?string $organizationId, ?string $kind, string $status, int $page): LengthAwarePaginator
    {
        $today = BusinessClock::todayString();
        $query = DB::table('hr_discipline_records as d')
            ->join('hr_employees as e', 'e.id', '=', 'd.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('hr_placements as pl', fn ($join) => $join
                ->on('pl.employee_id', '=', 'e.id')
                ->where('pl.is_primary', true)
                ->whereNull('pl.end_date'))
            ->leftJoin('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->where('d.tenant_id', $tenantId);

        $scope($query);

        if ($organizationId !== null) {
            $query->whereExists(fn (Builder $placements) => $placements->select(DB::raw(1))
                ->from('hr_placements as fp')
                ->whereColumn('fp.employee_id', 'e.id')
                ->where('fp.organization_id', $organizationId)
                ->whereRaw(EmployeeVisibility::currentPlacementSql('fp')));
        }

        if ($kind !== null) {
            $query->where('d.kind', $kind);
        }

        if ($status === self::FILTER_ACTIVE) {
            $query->whereNull('d.revoked_at')->whereNotNull('d.valid_until')->where('d.valid_until', '>=', $today);
        }

        $paginator = $query
            ->orderByDesc('d.issued_on')
            ->orderBy('p.name')
            ->orderBy('d.id')
            ->select(['d.id', 'd.employee_id', 'd.kind', 'd.issued_on', 'd.valid_until', 'd.revoked_at', 'p.name as employee_name', 'e.employee_number', 'o.name as organization'])
            ->paginate(self::PER_PAGE, page: max(1, $page));

        /** @var LengthAwarePaginator<int, ListRow> $mapped */
        $mapped = $paginator->through(fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'employee_id' => (string) $row->employee_id,
            'employee_name' => (string) $row->employee_name,
            'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
            'organization' => $row->organization === null ? null : (string) $row->organization,
            'kind' => (string) $row->kind,
            'kind_label' => self::kindLabel((string) $row->kind),
            'issued_on' => substr((string) $row->issued_on, 0, 10),
            'valid_until' => $row->valid_until === null ? null : substr((string) $row->valid_until, 0, 10),
            'status' => self::status($row, $today),
        ]);

        return $mapped;
    }

    /**
     * @return 'BERLAKU'|'KEDALUWARSA'|'TERCATAT'|'DICABUT'
     */
    private static function status(stdClass $row, string $today): string
    {
        if ($row->revoked_at !== null) {
            return 'DICABUT';
        }

        if ($row->valid_until === null) {
            return 'TERCATAT';
        }

        return substr((string) $row->valid_until, 0, 10) >= $today ? 'BERLAKU' : 'KEDALUWARSA';
    }

    private static function kindLabel(string $kind): string
    {
        return DisciplineKind::tryFrom($kind)?->label() ?? $kind;
    }
}
