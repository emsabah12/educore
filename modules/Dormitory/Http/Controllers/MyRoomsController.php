<?php

namespace Modules\Dormitory\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Application\Placement\PlacementDirectory;
use Modules\Dormitory\Application\Supervision\SupervisorDirectory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Kamar saya (PRD-008 §17.3, OD-DM-09): kamar yang diawasi pengguna sebagai wali kamar, beserta penghuninya. Hak
 * datang dari penugasan wali kamar aktif (bukan role); pegawai yang sudah tidak aktif tidak mendapat apa pun.
 * Hanya lihat. Halaman Asrama lain tetap butuh permission.
 */
final class MyRoomsController
{
    use HandlesFacilityActions;

    public function index(SupervisorDirectory $supervisors, FacilityDirectory $directory, PlacementDirectory $placements): Response
    {
        $tenantId = $this->tenantId();
        $roomIds = $supervisors->roomIdsOf($tenantId, $this->actorId());

        if ($roomIds === []) {
            abort(404);
        }

        $rooms = [];

        foreach (Room::query()->whereKey($roomIds)->orderBy('code')->get() as $room) {
            $detail = $directory->room($room);
            $rooms[] = [
                'room' => $detail['room'],
                'capacity' => $detail['capacity'],
                'occupancy' => $detail['occupancy'],
                'over_capacity' => $detail['over_capacity'],
                'holders' => $placements->holders($tenantId, $room->id),
                'supervisors' => $supervisors->forRoom($tenantId, $room->id),
            ];
        }

        return Inertia::render('dormitory/my-rooms', ['rooms' => $rooms]);
    }
}
