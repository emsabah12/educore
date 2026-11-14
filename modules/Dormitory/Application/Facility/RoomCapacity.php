<?php

namespace Modules\Dormitory\Application\Facility;

use Illuminate\Support\Facades\DB;
use Modules\Dormitory\Domain\Facility\CapacityBasis;
use Modules\Dormitory\Domain\Facility\ResourceStatus;
use Modules\Dormitory\Domain\Facility\Room;

/**
 * Kapasitas kamar (PRD-008 §4.4). Tidak pernah diketik: selalu dihitung dari bed/loker yang masih di inventaris
 * dan berstatus Tersedia.
 *
 * - Dasar Bed → jumlah bed tersedia; Loker → jumlah loker tersedia; Bed + loker → nilai terkecil keduanya.
 * - Slot pengawas adalah bagian dari kapasitas (§4.3): slot santri = kapasitas efektif − slot pengawas (min. 0).
 */
final class RoomCapacity
{
    /**
     * @return array{beds_total: int, beds_available: int, lockers_total: int, lockers_available: int, effective: int, supervisor_slots: int, resident_slots: int}
     */
    public static function summarize(CapacityBasis $basis, int $bedsTotal, int $bedsAvailable, int $lockersTotal, int $lockersAvailable, int $supervisorSlots): array
    {
        $effective = $basis->effectiveCapacity($bedsAvailable, $lockersAvailable);

        return [
            'beds_total' => $bedsTotal,
            'beds_available' => $bedsAvailable,
            'lockers_total' => $lockersTotal,
            'lockers_available' => $lockersAvailable,
            'effective' => $effective,
            'supervisor_slots' => $supervisorSlots,
            'resident_slots' => max(0, $effective - $supervisorSlots),
        ];
    }

    /**
     * Hitung langsung dari database untuk satu kamar.
     *
     * @return array{beds_total: int, beds_available: int, lockers_total: int, lockers_available: int, effective: int, supervisor_slots: int, resident_slots: int}
     */
    public static function forRoom(Room $room): array
    {
        $beds = self::countResources('dm_beds', $room->id);
        $lockers = self::countResources('dm_lockers', $room->id);

        return self::summarize($room->capacity_basis, $beds['total'], $beds['available'], $lockers['total'], $lockers['available'], $room->supervisor_slots);
    }

    /**
     * @return array{total: int, available: int}
     */
    private static function countResources(string $table, string $roomId): array
    {
        $row = DB::table($table)
            ->where('room_id', $roomId)
            ->where('is_active', true)
            ->selectRaw('count(*) as total, count(*) filter (where status = ?) as available', [ResourceStatus::Available->value])
            ->first();

        return ['total' => (int) ($row->total ?? 0), 'available' => (int) ($row->available ?? 0)];
    }
}
