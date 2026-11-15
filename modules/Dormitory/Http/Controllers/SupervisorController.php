<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Core\Domain\Person\Gender;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Placement\ResidentEligibility;
use Modules\Dormitory\Application\Supervision\ManageRoomSupervisors;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Supervision\RoomSupervisor;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Penugasan wali kamar (PRD-008 §17): cari calon, tambah, akhiri. Butuh `dormitory.facilities.manage` di node pemilik
 * asrama (§7 "atur wali kamar"; Gate dengan datanya: di luar cakupan 404, tanpa izin 403). Aturan ada di
 * ManageRoomSupervisors.
 */
final class SupervisorController
{
    use HandlesFacilityActions;

    /**
     * Calon wali kamar (pegawai aktif) untuk pop-up Tambah wali kamar (JSON). Yang tidak bisa dipilih tetap tampil
     * dengan alasannya.
     */
    public function candidates(Request $request, string $room, ResidentEligibility $eligibility): JsonResponse
    {
        $target = $this->room($room);
        $query = mb_substr(trim((string) $request->query('q')), 0, 100);

        $tenantId = $this->tenantId();
        $candidates = $eligibility->search($tenantId, $target->organization_id, PlacementCategory::Supervisor, $query);
        $ids = array_column($candidates, 'membership_id');
        $people = $eligibility->people($tenantId, $ids);
        $building = Building::query()->findOrFail($target->building_id);
        $assigned = array_flip(array_map('strval', DB::table('dm_room_supervisors')
            ->where('room_id', $target->id)->whereNull('end_on')->whereIn('membership_id', $ids)->pluck('membership_id')->all()));
        $result = [];

        foreach ($candidates as $candidate) {
            $person = $people[$candidate['membership_id']] ?? null;
            $result[] = $candidate + [
                'blocked' => match (true) {
                    $person === null || $person['gender'] === null => 'Jenis kelamin belum diisi',
                    $person['gender'] !== $building->gender => $person['gender'] === Gender::Male ? 'Laki-laki' : 'Perempuan',
                    isset($assigned[$candidate['membership_id']]) => 'Sudah menjadi wali kamar ini',
                    default => null,
                },
            ];
        }

        return response()->json(['candidates' => $result]);
    }

    public function store(Request $request, string $room, ManageRoomSupervisors $supervisors): RedirectResponse
    {
        $target = $this->room($room);

        $request->validate([
            'membership_id' => ['required', 'uuid'],
            'date' => ['required', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:'.ManageRoomSupervisors::NOTE_MAX_LENGTH],
        ], [
            'membership_id.required' => 'Pilih pegawai dulu.',
            'membership_id.uuid' => 'Pilih pegawai dari hasil pencarian.',
            'date.required' => 'Isi tanggal mulai.',
        ]);

        $this->attempt(fn (): RoomSupervisor => $supervisors->assign(
            $this->tenantId(),
            $target->id,
            $request->string('membership_id')->value(),
            $request->string('date')->value(),
            self::optional($request->input('note')),
            $this->actorId(),
        ));

        return $this->done('Wali kamar ditambahkan.');
    }

    public function end(Request $request, string $supervisor, ManageRoomSupervisors $supervisors): RedirectResponse
    {
        $target = Str::isUuid($supervisor) ? RoomSupervisor::query()->find($supervisor) : null;

        if (! $target instanceof RoomSupervisor) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $target);

        $request->validate([
            'date' => ['required', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:'.ManageRoomSupervisors::NOTE_MAX_LENGTH],
        ], ['date.required' => 'Isi tanggal selesai.']);

        $this->attempt(fn (): RoomSupervisor => $supervisors->end(
            $this->tenantId(),
            $target->id,
            $request->string('date')->value(),
            self::optional($request->input('note')),
            $this->actorId(),
        ));

        return $this->done('Penugasan wali kamar diakhiri.');
    }

    private function room(string $roomId): Room
    {
        $room = Str::isUuid($roomId) ? Room::query()->find($roomId) : null;

        if (! $room instanceof Room) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $room);

        return $room;
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
