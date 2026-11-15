<?php

namespace Modules\Dormitory\Application\Placement;

use Illuminate\Support\Facades\DB;
use Modules\Dormitory\Domain\Facility\ResourceStatus;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;

/**
 * Hunian kamar dari penempatan yang memegang tempat (Direncanakan + Aktif), PRD-008 §16.3, §16.5. Tidak disimpan
 * di kamar/bed; selalu dihitung.
 *
 * @phpstan-type Occupancy array{active: int, planned: int, residents: int, supervisors: int, unusable: int}
 */
final class RoomOccupancy
{
    /**
     * Hunian satu kamar + bed/loker yang sedang dipegang (bed/loker id → penempatan id).
     *
     * @return array{active: int, planned: int, residents: int, supervisors: int, beds: array<string, string>, lockers: array<string, string>}
     */
    public static function forRoom(string $roomId, ?string $exceptPlacementId = null): array
    {
        $result = ['active' => 0, 'planned' => 0, 'residents' => 0, 'supervisors' => 0, 'beds' => [], 'lockers' => []];

        foreach (DB::table('dm_placements')
            ->where('room_id', $roomId)
            ->whereIn('status', PlacementStatus::holdingValues())
            ->when($exceptPlacementId !== null, fn ($query) => $query->where('id', '<>', $exceptPlacementId))
            ->get(['id', 'status', 'category', 'bed_id', 'locker_id']) as $row) {
            $row->status === PlacementStatus::Active->value ? $result['active']++ : $result['planned']++;
            $row->category === PlacementCategory::Resident->value ? $result['residents']++ : $result['supervisors']++;

            if ($row->bed_id !== null) {
                $result['beds'][(string) $row->bed_id] = (string) $row->id;
            }

            if ($row->locker_id !== null) {
                $result['lockers'][(string) $row->locker_id] = (string) $row->id;
            }
        }

        return $result;
    }

    /**
     * Hunian banyak kamar sekaligus. `unusable` = penempatan yang memegang bed/loker yang kini tidak tersedia atau
     * nonaktif (tanda kelebihan muatan).
     *
     * @param  list<string>  $roomIds
     * @return array<string, Occupancy>
     */
    public static function forRooms(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        $available = ResourceStatus::Available->value;
        $result = [];

        foreach (DB::table('dm_placements as pl')
            ->leftJoin('dm_beds as b', 'b.id', '=', 'pl.bed_id')
            ->leftJoin('dm_lockers as l', 'l.id', '=', 'pl.locker_id')
            ->whereIn('pl.room_id', $roomIds)
            ->whereIn('pl.status', PlacementStatus::holdingValues())
            ->groupBy('pl.room_id')
            ->selectRaw('pl.room_id')
            ->selectRaw('count(*) filter (where pl.status = ?) as active', [PlacementStatus::Active->value])
            ->selectRaw('count(*) filter (where pl.status = ?) as planned', [PlacementStatus::Planned->value])
            ->selectRaw('count(*) filter (where pl.category = ?) as residents', [PlacementCategory::Resident->value])
            ->selectRaw('count(*) filter (where pl.category = ?) as supervisors', [PlacementCategory::Supervisor->value])
            ->selectRaw(
                'count(*) filter (where (b.id is not null and (b.is_active = false or b.status <> ?)) or (l.id is not null and (l.is_active = false or l.status <> ?))) as unusable',
                [$available, $available],
            )
            ->get() as $row) {
            $result[(string) $row->room_id] = [
                'active' => (int) $row->active,
                'planned' => (int) $row->planned,
                'residents' => (int) $row->residents,
                'supervisors' => (int) $row->supervisors,
                'unusable' => (int) $row->unusable,
            ];
        }

        return $result;
    }

    /**
     * @return array{active: int, planned: int, residents: int, supervisors: int, unusable: int}
     */
    public static function empty(): array
    {
        return ['active' => 0, 'planned' => 0, 'residents' => 0, 'supervisors' => 0, 'unusable' => 0];
    }

    /**
     * Kelebihan muatan (PRD-008 §16.5): pemegang tempat melebihi kapasitas efektif, atau ada yang memegang bed/loker
     * yang tidak bisa dipakai.
     *
     * @param  array{active: int, planned: int, residents: int, supervisors: int, unusable: int}  $occupancy
     */
    public static function isOverCapacity(array $occupancy, int $effectiveCapacity): bool
    {
        return $effectiveCapacity < $occupancy['active'] + $occupancy['planned'] || $occupancy['unusable'] > 0;
    }
}
