<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Leave\LeaveType;
use stdClass;

/**
 * Saldo cuti per pegawai per tahun untuk Admin SDM (PRD-001 §13.5, HR-3d-b): halaman Saldo cuti
 * dan lembar "Saldo" di rekap .xlsx. Hak akses dicek pemanggil.
 *
 * Pegawai = pegawai AKTIF; lembaga = penempatan utama yang masih berjalan (bisa kosong).
 * Saringan: tahun, lembaga/unit (termasuk bawahannya), jenis berjatah, satu pegawai, cari nama/nomor induk.
 *
 * @phpstan-type Filters array{tahun: int, lembaga: string, jenis: string, pegawai: string, cari: string}
 * @phpstan-type Balance array{type_id: string, name: string, quota: int, adjustment: int, used: int, pending: int, remaining: int}
 * @phpstan-type AdjustmentRow array{id: string, type: string, days: int, reason: string, by: string|null, created_at: string}
 * @phpstan-type Row array{id: string, name: string, employee_number: string|null, organization: string|null, balances: list<Balance>, adjustments: list<AdjustmentRow>}
 */
final class LeaveBalanceSheet
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * Saringan yang tidak valid diabaikan. Tahun: 5 tahun ke belakang s.d. tahun depan.
     *
     * @param  array<array-key, mixed>  $query
     * @return Filters
     */
    public static function filters(array $query, int $thisYear): array
    {
        $text = function (string $key) use ($query): string {
            $value = $query[$key] ?? null;

            return is_string($value) ? trim($value) : '';
        };

        $year = (int) $text('tahun');

        return [
            'tahun' => $year >= $thisYear - 5 && $year <= $thisYear + 1 ? $year : $thisYear,
            'lembaga' => Str::isUuid($text('lembaga')) ? $text('lembaga') : '',
            'jenis' => Str::isUuid($text('jenis')) ? $text('jenis') : '',
            'pegawai' => Str::isUuid($text('pegawai')) ? $text('pegawai') : '',
            'cari' => mb_substr($text('cari'), 0, 100),
        ];
    }

    /**
     * Jenis berjatah yang aktif (atau satu jenis terpilih), urut nama.
     *
     * @return Collection<int, LeaveType>
     */
    public function types(string $tenantId, string $typeId = ''): Collection
    {
        return LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('has_quota', true)
            ->when($typeId !== '', fn ($query) => $query->whereKey($typeId), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Filters  $filters
     * @return array{rows: list<Row>, page: LengthAwarePaginator<int, stdClass>}
     */
    public function page(string $tenantId, array $filters): array
    {
        /** @var LengthAwarePaginator<int, stdClass> $page */
        $page = $this->employees($tenantId, $filters)->paginate(self::PER_PAGE);

        /** @var list<stdClass> $items */
        $items = array_values($page->items());

        return ['rows' => $this->rows($tenantId, $filters, $items, withAdjustments: true), 'page' => $page->withQueryString()];
    }

    /**
     * Semua pegawai yang cocok, tanpa halaman (untuk rekap .xlsx).
     *
     * @param  Filters  $filters
     * @return list<Row>
     */
    public function all(string $tenantId, array $filters): array
    {
        /** @var list<stdClass> $items */
        $items = $this->employees($tenantId, $filters)->get()->values()->all();

        return $this->rows($tenantId, $filters, $items, withAdjustments: false);
    }

    /**
     * @param  Filters  $filters
     */
    private function employees(string $tenantId, array $filters): Builder
    {
        $query = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('hr_placements as pl', fn (JoinClause $join) => $join
                ->on('pl.employee_id', '=', 'e.id')
                ->where('pl.is_primary', true)
                ->whereNull('pl.end_date'))
            ->leftJoin('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', EmployeeStatus::Active->value);

        if ($filters['lembaga'] !== '') {
            $query->whereIn('pl.organization_id', fn (Builder $sub) => $sub
                ->select('descendant_id')
                ->from('organization_closure')
                ->where('tenant_id', $tenantId)
                ->where('ancestor_id', $filters['lembaga']));
        }

        if ($filters['pegawai'] !== '') {
            $query->where('e.id', $filters['pegawai']);
        }

        if ($filters['cari'] !== '') {
            $pattern = '%'.addcslashes($filters['cari'], '%_\\').'%';
            $query->where(fn (Builder $search) => $search->where('p.name', 'ilike', $pattern)->orWhere('e.employee_number', 'ilike', $pattern));
        }

        return $query
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->select(['e.id', 'e.employee_number', 'p.name', 'o.name as organization']);
    }

    /**
     * @param  Filters  $filters
     * @param  list<stdClass>  $items
     * @return list<Row>
     */
    private function rows(string $tenantId, array $filters, array $items, bool $withAdjustments): array
    {
        $ids = array_map(fn (stdClass $row): string => (string) $row->id, $items);
        $types = $this->types($tenantId, $filters['jenis']);
        $table = $this->balances->table($ids, $types, $filters['tahun']);
        $adjustments = $withAdjustments ? $this->adjustments($ids, $filters['tahun']) : [];

        $rows = [];

        foreach ($items as $item) {
            $id = (string) $item->id;

            $rows[] = [
                'id' => $id,
                'name' => (string) $item->name,
                'employee_number' => $item->employee_number === null ? null : (string) $item->employee_number,
                'organization' => $item->organization === null ? null : (string) $item->organization,
                'balances' => array_values($table[$id] ?? []),
                'adjustments' => $adjustments[$id] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Riwayat penyesuaian tahun itu, terbaru dulu.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<AdjustmentRow>>
     */
    private function adjustments(array $employeeIds, int $year): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = DB::table('hr_leave_adjustments as a')
            ->join('hr_leave_types as t', 't.id', '=', 'a.leave_type_id')
            ->leftJoin('memberships as m', 'm.id', '=', 'a.created_by_membership_id')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
            ->whereIn('a.employee_id', $employeeIds)
            ->where('a.year', $year)
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.id')
            ->get(['a.id', 'a.employee_id', 'a.days', 'a.reason', 'a.created_at', 't.name as type', 'p.name as by']);

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->employee_id][] = [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'days' => (int) $row->days,
                'reason' => (string) $row->reason,
                'by' => $row->by === null ? null : (string) $row->by,
                'created_at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            ];
        }

        return $result;
    }
}
