<?php

namespace Modules\Dormitory\Application\Mutation;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Person\Gender;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Application\Placement\ManagePlacements;
use Modules\Dormitory\Application\Placement\ResidentEligibility;
use Modules\Dormitory\Application\Placement\RoomOccupancy;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Mutation\EmergencyCategory;
use Modules\Dormitory\Domain\Mutation\MutationStatus;
use Modules\Dormitory\Domain\Mutation\MutationType;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use stdClass;

/**
 * Data tampilan mutasi (PRD-008 §18.3): pilihan kamar tujuan, daftar per tab, tanda pengajuan terbuka di halaman
 * kamar, bed/loker untuk pop-up Setujui, dan angka kartu Beranda. Saringan cakupan diberikan pemanggil.
 *
 * @phpstan-type MutationRow array{id: string, type: string, type_label: string, status: string, status_label: string, category_label: string|null, reason: string, membership_id: string, placement_id: string, name: string, organization_id: string, to_organization_id: string, from_room: string, from_room_id: string, from_dormitory: string, to_room: string, to_room_id: string, to_dormitory: string, requested_by: string|null, requested_by_name: string|null, requested_at: string, decided_by_name: string|null, decided_at: string|null, decision_note: string|null, executed_on: string|null, reviewed: bool, reviewed_by_name: string|null, reviewed_at: string|null, review_note: string|null, recent: bool}
 * @phpstan-type MutationPage array{data: list<MutationRow>, total: int, from: int|null, to: int|null, last_page: int, prev_page_url: string|null, next_page_url: string|null}
 * @phpstan-type TargetRoom array{id: string, code: string, name: string|null, building: string, dormitory: string, dormitory_id: string, free: int, blocked: string|null}
 */
final class MutationDirectory
{
    public const PER_PAGE = 25;

    public function __construct(
        private readonly FacilityDirectory $facilities,
        private readonly ResidentEligibility $eligibility,
    ) {}

    /**
     * Kamar tujuan yang mungkin untuk santri ini: kamar aktif di gedung & asrama aktif, jenis kelamin cocok, di asrama
     * milik salah satu $ownerIds tempat santri layak. Kamar penuh/kelebihan muatan tetap tampil dengan alasannya.
     *
     * @param  list<string>  $ownerIds
     * @return list<TargetRoom>
     */
    public function targets(string $tenantId, Placement $placement, array $ownerIds): array
    {
        $person = $this->eligibility->people($tenantId, [$placement->membership_id])[$placement->membership_id] ?? null;

        if ($person === null || ! $person['gender'] instanceof Gender || $ownerIds === []) {
            return [];
        }

        $eligibleOwners = array_values(array_filter(
            $ownerIds,
            fn (string $ownerId): bool => $this->eligibility->isEligible($tenantId, $ownerId, $placement->membership_id, PlacementCategory::Resident),
        ));

        if ($eligibleOwners === []) {
            return [];
        }

        $gender = $person['gender']->value;
        $rows = $this->facilities->roomsMatching(fn (Builder $query): Builder => $query
            ->join('dm_buildings as b', 'b.id', '=', 'r.building_id')
            ->join('dm_dormitories as d', 'd.id', '=', 'r.dormitory_id')
            ->where('r.tenant_id', $tenantId)
            ->whereIn('r.organization_id', $eligibleOwners)
            ->where('r.id', '<>', $placement->room_id)
            ->where('r.is_active', true)
            ->where('b.is_active', true)
            ->where('d.is_active', true)
            ->where('b.gender', $gender)
            ->addSelect('b.name as building_name', 'b.code as building_code', 'd.name as dormitory_name')
            ->reorder()
            ->orderBy('d.name')
            ->orderBy('b.code')
            ->orderBy('r.code'));

        $names = DB::table('dm_rooms as r')
            ->join('dm_buildings as b', 'b.id', '=', 'r.building_id')
            ->join('dm_dormitories as d', 'd.id', '=', 'r.dormitory_id')
            ->whereIn('r.id', array_column($rows, 'id'))
            ->get(['r.id', 'b.name as building', 'd.name as dormitory'])
            ->keyBy('id');

        $targets = [];

        foreach ($rows as $row) {
            $taken = $row['occupancy']['active'] + $row['occupancy']['planned'];
            $free = max(0, min($row['capacity']['resident_slots'] - $row['occupancy']['residents'], $row['capacity']['effective'] - $taken));
            $label = $names[$row['id']] ?? null;
            $targets[] = [
                'id' => $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'building' => $label === null ? '' : (string) $label->building,
                'dormitory' => $label === null ? '' : (string) $label->dormitory,
                'dormitory_id' => $row['dormitory_id'],
                'free' => $row['over_capacity'] ? 0 : $free,
                'blocked' => match (true) {
                    $row['over_capacity'] => 'Kelebihan muatan',
                    $free === 0 => 'Penuh',
                    default => null,
                },
            ];
        }

        return $targets;
    }

    /**
     * Daftar mutasi untuk satu tab, disaring asal ATAU tujuan dalam $nodeIds.
     *
     * @param  list<string>  $nodeIds
     * @return MutationPage
     */
    public function page(string $tenantId, array $nodeIds, string $tab): array
    {
        $query = $this->base($tenantId)
            ->where(fn (Builder $query) => $query->whereIn('mu.organization_id', $nodeIds)->orWhereIn('mu.to_organization_id', $nodeIds));

        match ($tab) {
            'menunggu' => $query->where('mu.status', MutationStatus::Requested->value)->orderBy('mu.created_at')->orderBy('mu.id'),
            'darurat' => $query->where('mu.type', MutationType::Emergency->value)
                ->orderByRaw('mu.reviewed_at IS NOT NULL')->orderByDesc('mu.created_at')->orderByDesc('mu.id'),
            default => $query->orderByDesc('mu.created_at')->orderByDesc('mu.id'),
        };

        /** @var LengthAwarePaginator<int, stdClass> $page */
        $page = $query->paginate(self::PER_PAGE, ['*'], 'halaman')->withQueryString();
        $data = [];

        foreach ($page->items() as $item) {
            $data[] = self::row($item);
        }

        return [
            'data' => $data,
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'last_page' => $page->lastPage(),
            'prev_page_url' => $page->previousPageUrl(),
            'next_page_url' => $page->nextPageUrl(),
        ];
    }

    /**
     * Jumlah untuk lencana tab & kartu Beranda: pengajuan menunggu & darurat belum ditinjau, asal DAN tujuan dalam
     * cakupan penyetuju.
     *
     * @param  list<string>  $nodeIds
     * @return array{pending: int, unreviewed: int}
     */
    public function counts(string $tenantId, array $nodeIds): array
    {
        if ($nodeIds === []) {
            return ['pending' => 0, 'unreviewed' => 0];
        }

        $scoped = fn () => DB::table('dm_mutations')
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $nodeIds)
            ->whereIn('to_organization_id', $nodeIds);

        return [
            'pending' => $scoped()->where('status', MutationStatus::Requested->value)->count(),
            'unreviewed' => $scoped()->where('type', MutationType::Emergency->value)->whereNull('reviewed_at')->count(),
        ];
    }

    /**
     * Pengajuan terbuka per penempatan (tanda "Mutasi diajukan ke …" di halaman kamar & Kamar saya).
     *
     * @param  list<string>  $placementIds
     * @return array<string, array{id: string, to_room: string, requested_by: string|null}>
     */
    public function openForPlacements(string $tenantId, array $placementIds): array
    {
        if ($placementIds === []) {
            return [];
        }

        $open = [];

        foreach (DB::table('dm_mutations as mu')
            ->join('dm_rooms as r', 'r.id', '=', 'mu.to_room_id')
            ->where('mu.tenant_id', $tenantId)
            ->whereIn('mu.placement_id', $placementIds)
            ->where('mu.status', MutationStatus::Requested->value)
            ->get(['mu.id', 'mu.placement_id', 'mu.requested_by', 'r.code']) as $row) {
            $open[(string) $row->placement_id] = [
                'id' => (string) $row->id,
                'to_room' => (string) $row->code,
                'requested_by' => $row->requested_by === null ? null : (string) $row->requested_by,
            ];
        }

        return $open;
    }

    /**
     * Bed/loker kamar tujuan untuk pop-up Setujui (sama dengan pilihan di halaman kamar).
     *
     * @return array{basis: string, beds: list<array{id: string, code: string}>, lockers: list<array{id: string, code: string}>, suggestion: array{bed: string|null, locker: string|null}}
     */
    public function targetResources(string $roomId): array
    {
        $room = Room::query()->withoutGlobalScope('tenant')->whereKey($roomId)->firstOrFail();
        $occupancy = RoomOccupancy::forRoom($room->id);
        $free = fn (string $table, array $held): array => array_values(array_map(
            fn (object $row): array => ['id' => (string) $row->id, 'code' => (string) $row->code],
            DB::table($table)->where('room_id', $room->id)->where('is_active', true)->where('status', 'TERSEDIA')
                ->whereNotIn('id', $held)->orderBy('code')->get(['id', 'code'])->all(),
        ));

        return [
            'basis' => $room->capacity_basis->value,
            'beds' => $free('dm_beds', array_keys($occupancy['beds'])),
            'lockers' => $free('dm_lockers', array_keys($occupancy['lockers'])),
            'suggestion' => ManagePlacements::suggestion($room),
        ];
    }

    private function base(string $tenantId): Builder
    {
        return DB::table('dm_mutations as mu')
            ->join('memberships as m', 'm.id', '=', 'mu.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('dm_rooms as fr', 'fr.id', '=', 'mu.from_room_id')
            ->join('dm_dormitories as fd', 'fd.id', '=', 'mu.dormitory_id')
            ->join('dm_rooms as tr', 'tr.id', '=', 'mu.to_room_id')
            ->join('dm_dormitories as td', 'td.id', '=', 'mu.to_dormitory_id')
            ->leftJoin('memberships as rm', 'rm.id', '=', 'mu.requested_by')
            ->leftJoin('persons as rp', 'rp.id', '=', 'rm.person_id')
            ->leftJoin('memberships as dm', 'dm.id', '=', 'mu.decided_by')
            ->leftJoin('persons as dp', 'dp.id', '=', 'dm.person_id')
            ->leftJoin('memberships as vm', 'vm.id', '=', 'mu.reviewed_by')
            ->leftJoin('persons as vp', 'vp.id', '=', 'vm.person_id')
            ->where('mu.tenant_id', $tenantId)
            ->select([
                'mu.*', 'p.name',
                'fr.code as from_room', 'fd.name as from_dormitory',
                'tr.code as to_room', 'td.name as to_dormitory',
                'rp.name as requested_by_name', 'dp.name as decided_by_name', 'vp.name as reviewed_by_name',
            ]);
    }

    /**
     * @return MutationRow
     */
    private static function row(stdClass $row): array
    {
        $type = MutationType::from((string) $row->type);
        $status = MutationStatus::from((string) $row->status);
        $category = $row->emergency_category === null ? null : EmergencyCategory::from((string) $row->emergency_category);
        $string = fn (mixed $value): ?string => $value === null ? null : (string) $value;
        $createdAt = (string) $row->created_at;

        return [
            'id' => (string) $row->id,
            'type' => $type->value,
            'type_label' => $type->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'category_label' => $category?->label(),
            'reason' => (string) $row->reason,
            'membership_id' => (string) $row->membership_id,
            'placement_id' => (string) $row->placement_id,
            'name' => (string) $row->name,
            'organization_id' => (string) $row->organization_id,
            'to_organization_id' => (string) $row->to_organization_id,
            'from_room' => (string) $row->from_room,
            'from_room_id' => (string) $row->from_room_id,
            'from_dormitory' => (string) $row->from_dormitory,
            'to_room' => (string) $row->to_room,
            'to_room_id' => (string) $row->to_room_id,
            'to_dormitory' => (string) $row->to_dormitory,
            'requested_by' => $string($row->requested_by),
            'requested_by_name' => $string($row->requested_by_name),
            'requested_at' => $createdAt,
            'decided_by_name' => $string($row->decided_by_name),
            'decided_at' => $string($row->decided_at),
            'decision_note' => $string($row->decision_note),
            'executed_on' => $string($row->executed_on),
            'reviewed' => $row->reviewed_at !== null,
            'reviewed_by_name' => $string($row->reviewed_by_name),
            'reviewed_at' => $string($row->reviewed_at),
            'review_note' => $string($row->review_note),
            'recent' => strtotime($createdAt) >= now()->subDay()->getTimestamp(),
        ];
    }
}
