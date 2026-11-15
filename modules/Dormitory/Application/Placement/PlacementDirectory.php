<?php

namespace Modules\Dormitory\Application\Placement;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Dormitory\Domain\Placement\EndReason;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;
use stdClass;

/**
 * Data halaman penghuni asrama (PRD-008 §16.6). Penyaringan cakupan dilakukan di query (node pemilik asrama yang
 * terlihat); nama dari Person, lembaga/kelas santri dari kontrak Academic.
 *
 * @phpstan-type PlacementRow array{id: string, membership_id: string, name: string, detail: string, category: string, category_label: string, status: string, status_label: string, planned_on: string|null, check_in_on: string|null, check_out_on: string|null, end_reason_label: string|null, cancel_reason: string|null, note: string|null, bed: string|null, bed_id: string|null, locker: string|null, locker_id: string|null, resource_problem: bool, dormitory: string, dormitory_id: string, building: string, room: string, room_id: string}
 * @phpstan-type PlacementPage array{data: list<PlacementRow>, total: int, from: int|null, to: int|null, last_page: int, prev_page_url: string|null, next_page_url: string|null}
 */
final class PlacementDirectory
{
    public const PER_PAGE = 25;

    public const HISTORY_PER_PAGE = 50;

    public function __construct(private readonly ResidentEligibility $eligibility) {}

    /**
     * Penghuni Aktif & Direncanakan sebuah kamar (Aktif dulu, lalu nama).
     *
     * @return list<PlacementRow>
     */
    public function holders(string $tenantId, string $roomId): array
    {
        $rows = $this->base($tenantId)
            ->where('pl.room_id', $roomId)
            ->whereIn('pl.status', PlacementStatus::holdingValues())
            ->orderByRaw('pl.status = ? desc', [PlacementStatus::Active->value])
            ->orderBy('p.name')
            ->orderBy('pl.id')
            ->get();

        return $this->present($tenantId, self::list($rows));
    }

    /**
     * Riwayat kamar: penempatan Selesai & Dibatalkan, terbaru dulu.
     *
     * @return PlacementPage
     */
    public function roomHistory(string $tenantId, string $roomId): array
    {
        /** @var LengthAwarePaginator<int, stdClass> $page */
        $page = $this->base($tenantId)
            ->where('pl.room_id', $roomId)
            ->whereIn('pl.status', [PlacementStatus::Ended->value, PlacementStatus::Cancelled->value])
            ->orderByDesc('pl.updated_at')
            ->orderByDesc('pl.id')
            ->paginate(self::HISTORY_PER_PAGE, ['*'], 'halaman')
            ->withQueryString();

        return $this->page($tenantId, $page);
    }

    /**
     * Penghuni di asrama yang terlihat.
     *
     * @param  list<string>  $organizationIds  node pemilik yang terlihat
     * @param  array{dormitory: string|null, building: string|null, status: string, category: string|null, q: string}  $filters
     * @return PlacementPage
     */
    public function search(string $tenantId, array $organizationIds, array $filters): array
    {
        $statuses = match ($filters['status']) {
            'aktif' => [PlacementStatus::Active->value],
            'rencana' => [PlacementStatus::Planned->value],
            default => PlacementStatus::holdingValues(),
        };
        $text = trim((string) preg_replace('/\s+/u', ' ', $filters['q']));

        /** @var LengthAwarePaginator<int, stdClass> $page */
        $page = $this->base($tenantId)
            ->whereIn('pl.organization_id', $organizationIds)
            ->whereIn('pl.status', $statuses)
            ->when($filters['dormitory'] !== null, fn (Builder $query) => $query->where('pl.dormitory_id', $filters['dormitory']))
            ->when($filters['building'] !== null, fn (Builder $query) => $query->where('r.building_id', $filters['building']))
            ->when($filters['category'] !== null, fn (Builder $query) => $query->where('pl.category', $filters['category']))
            ->when($text !== '', fn (Builder $query) => $query->where('p.name', 'ilike', '%'.addcslashes($text, '%_\\').'%'))
            ->orderBy('d.name')
            ->orderBy('g.code')
            ->orderBy('r.code')
            ->orderBy('p.name')
            ->orderBy('pl.id')
            ->paginate(self::PER_PAGE, ['*'], 'halaman')
            ->withQueryString();

        return $this->page($tenantId, $page);
    }

    /**
     * Riwayat hunian seseorang di asrama yang terlihat (semua status, terbaru dulu).
     *
     * @param  list<string>  $organizationIds
     * @return array{name: string, detail: string, rows: list<PlacementRow>}|null
     */
    public function person(string $tenantId, string $membershipId, array $organizationIds): ?array
    {
        $rows = self::list($this->base($tenantId)
            ->where('pl.membership_id', $membershipId)
            ->whereIn('pl.organization_id', $organizationIds)
            ->orderByRaw('coalesce(pl.check_in_on, pl.planned_on, pl.created_at::date) desc')
            ->orderByDesc('pl.created_at')
            ->get());

        if ($rows === []) {
            return null;
        }

        $present = $this->present($tenantId, $rows);

        return ['name' => $present[0]['name'], 'detail' => $present[0]['detail'], 'rows' => $present];
    }

    /**
     * Pemegang bed/loker sebuah kamar untuk petak di halaman kamar: id bed/loker → nama + status.
     *
     * @return array{beds: array<string, array{name: string, status: string}>, lockers: array<string, array{name: string, status: string}>}
     */
    public function resourceHolders(string $tenantId, string $roomId): array
    {
        $result = ['beds' => [], 'lockers' => []];

        foreach ($this->base($tenantId)
            ->where('pl.room_id', $roomId)
            ->whereIn('pl.status', PlacementStatus::holdingValues())
            ->get(['pl.bed_id', 'pl.locker_id', 'pl.status', 'p.name']) as $row) {
            $holder = ['name' => (string) $row->name, 'status' => (string) $row->status];

            if ($row->bed_id !== null) {
                $result['beds'][(string) $row->bed_id] = $holder;
            }

            if ($row->locker_id !== null) {
                $result['lockers'][(string) $row->locker_id] = $holder;
            }
        }

        return $result;
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function base(string $tenantId): Builder
    {
        return DB::table('dm_placements as pl')
            ->join('memberships as m', 'm.id', '=', 'pl.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('dm_rooms as r', 'r.id', '=', 'pl.room_id')
            ->join('dm_buildings as g', 'g.id', '=', 'r.building_id')
            ->join('dm_dormitories as d', 'd.id', '=', 'pl.dormitory_id')
            ->leftJoin('dm_beds as b', 'b.id', '=', 'pl.bed_id')
            ->leftJoin('dm_lockers as l', 'l.id', '=', 'pl.locker_id')
            ->where('pl.tenant_id', $tenantId)
            ->select([
                'pl.*',
                'p.name',
                'r.code as room_code',
                'g.name as building_name',
                'd.name as dormitory_name',
                'b.code as bed_code',
                'l.code as locker_code',
                DB::raw("(b.id IS NOT NULL AND (b.is_active = false OR b.status <> 'TERSEDIA')) OR (l.id IS NOT NULL AND (l.is_active = false OR l.status <> 'TERSEDIA')) AS resource_problem"),
            ]);
    }

    /**
     * @param  LengthAwarePaginator<int, stdClass>  $page
     * @return PlacementPage
     */
    private function page(string $tenantId, LengthAwarePaginator $page): array
    {
        $items = [];

        foreach ($page->items() as $item) {
            $items[] = $item;
        }

        return [
            'data' => $this->present($tenantId, $items),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'last_page' => $page->lastPage(),
            'prev_page_url' => $page->previousPageUrl(),
            'next_page_url' => $page->nextPageUrl(),
        ];
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return list<stdClass>
     */
    private static function list(iterable $rows): array
    {
        $list = [];

        foreach ($rows as $row) {
            $list[] = $row;
        }

        return $list;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<PlacementRow>
     */
    private function present(string $tenantId, array $rows): array
    {
        $studentIds = [];

        foreach ($rows as $row) {
            if ($row->category === PlacementCategory::Resident->value) {
                $studentIds[] = (string) $row->membership_id;
            }
        }

        $details = $this->eligibility->studentDetails($tenantId, array_values(array_unique($studentIds)));
        $result = [];

        foreach ($rows as $row) {
            $category = PlacementCategory::from((string) $row->category);
            $status = PlacementStatus::from((string) $row->status);
            $reason = $row->end_reason === null ? null : EndReason::from((string) $row->end_reason);

            $result[] = [
                'id' => (string) $row->id,
                'membership_id' => (string) $row->membership_id,
                'name' => (string) $row->name,
                'detail' => $category === PlacementCategory::Resident
                    ? ($details[(string) $row->membership_id] ?? 'Tidak ada pendaftaran aktif')
                    : 'Pegawai',
                'category' => $category->value,
                'category_label' => $category->label(),
                'status' => $status->value,
                'status_label' => $status->label(),
                'planned_on' => self::day($row->planned_on),
                'check_in_on' => self::day($row->check_in_on),
                'check_out_on' => self::day($row->check_out_on),
                'end_reason_label' => $reason?->label(),
                'cancel_reason' => $row->cancel_reason === null ? null : (string) $row->cancel_reason,
                'note' => $row->note === null ? null : (string) $row->note,
                'bed' => $row->bed_code === null ? null : (string) $row->bed_code,
                'bed_id' => $row->bed_id === null ? null : (string) $row->bed_id,
                'locker' => $row->locker_code === null ? null : (string) $row->locker_code,
                'locker_id' => $row->locker_id === null ? null : (string) $row->locker_id,
                'resource_problem' => in_array($status, [PlacementStatus::Planned, PlacementStatus::Active], true) && (bool) $row->resource_problem,
                'dormitory' => (string) $row->dormitory_name,
                'dormitory_id' => (string) $row->dormitory_id,
                'building' => (string) $row->building_name,
                'room' => (string) $row->room_code,
                'room_id' => (string) $row->room_id,
            ];
        }

        return $result;
    }

    private static function day(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
