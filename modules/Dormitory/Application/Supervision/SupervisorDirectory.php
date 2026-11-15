<?php

namespace Modules\Dormitory\Application\Supervision;

use Illuminate\Support\Facades\DB;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Data tampilan wali kamar (PRD-008 §17.3): per kamar, per asrama, dan Kamar saya. Nama dibaca dari Person; status
 * pegawai dari kontrak HR (pegawai yang berhenti tetap tampil, ditandai "Bukan pegawai aktif").
 *
 * @phpstan-type SupervisorRow array{id: string, membership_id: string, name: string, start_on: string, end_on: string|null, note: string|null, lives_here: bool, employee_active: bool}
 */
final class SupervisorDirectory
{
    public const HISTORY_LIMIT = 50;

    public function __construct(
        private readonly ActiveEmployees $employees,
    ) {}

    /**
     * Wali kamar aktif satu kamar, urut tanggal mulai.
     *
     * @return list<SupervisorRow>
     */
    public function forRoom(string $tenantId, string $roomId): array
    {
        return $this->rows($tenantId, [$roomId], true)[$roomId] ?? [];
    }

    /**
     * Penugasan yang sudah selesai di satu kamar, terbaru dulu (maks. 50).
     *
     * @return list<SupervisorRow>
     */
    public function historyForRoom(string $tenantId, string $roomId): array
    {
        return $this->rows($tenantId, [$roomId], false)[$roomId] ?? [];
    }

    /**
     * Wali kamar aktif untuk banyak kamar (tab Wali kamar di detail asrama).
     *
     * @param  list<string>  $roomIds
     * @return array<string, list<SupervisorRow>> room_id → wali kamar aktif
     */
    public function forRooms(string $tenantId, array $roomIds): array
    {
        return $roomIds === [] ? [] : $this->rows($tenantId, $roomIds, true);
    }

    /**
     * Kamar yang sedang diawasi orang ini. Kosong bila ia bukan (lagi) pegawai aktif (§17.2 butir 6).
     *
     * @return list<string> room_id
     */
    public function roomIdsOf(string $tenantId, string $membershipId): array
    {
        if ($this->employees->activeMembershipIds($tenantId, [$membershipId]) === []) {
            return [];
        }

        $ids = [];

        foreach (DB::table('dm_room_supervisors as s')
            ->join('dm_rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.tenant_id', $tenantId)
            ->where('s.membership_id', $membershipId)
            ->whereNull('s.end_on')
            ->orderBy('r.code')
            ->pluck('s.room_id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $roomIds
     * @return array<string, list<SupervisorRow>>
     */
    private function rows(string $tenantId, array $roomIds, bool $active): array
    {
        $query = DB::table('dm_room_supervisors as s')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.room_id', $roomIds);

        $records = $active
            ? $query->whereNull('s.end_on')->orderBy('s.start_on')->orderBy('p.name')->get(['s.*', 'p.name'])
            : $query->whereNotNull('s.end_on')->orderByDesc('s.end_on')->orderByDesc('s.id')->limit(self::HISTORY_LIMIT)->get(['s.*', 'p.name']);

        $membershipIds = [];

        foreach ($records as $record) {
            $membershipIds[] = (string) $record->membership_id;
        }

        $membershipIds = array_values(array_unique($membershipIds));
        $activeEmployees = array_flip($this->employees->activeMembershipIds($tenantId, $membershipIds));
        $livesHere = [];

        // Wali kamar yang juga tinggal (penempatan Pengawas aktif) di kamar yang sama.
        foreach (DB::table('dm_placements')
            ->where('tenant_id', $tenantId)
            ->whereIn('room_id', $roomIds)
            ->whereIn('membership_id', $membershipIds)
            ->where('category', PlacementCategory::Supervisor->value)
            ->where('status', PlacementStatus::Active->value)
            ->get(['room_id', 'membership_id']) as $placement) {
            $livesHere[$placement->room_id.'|'.$placement->membership_id] = true;
        }

        $grouped = [];

        foreach ($records as $record) {
            $roomId = (string) $record->room_id;
            $membershipId = (string) $record->membership_id;
            $grouped[$roomId][] = [
                'id' => (string) $record->id,
                'membership_id' => $membershipId,
                'name' => (string) $record->name,
                'start_on' => (string) $record->start_on,
                'end_on' => $record->end_on === null ? null : (string) $record->end_on,
                'note' => $record->note === null ? null : (string) $record->note,
                'lives_here' => isset($livesHere[$roomId.'|'.$membershipId]),
                'employee_active' => isset($activeEmployees[$membershipId]),
            ];
        }

        return $grouped;
    }
}
