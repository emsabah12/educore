<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Facility\FacilityCode;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Application\Facility\ManageRoomResources;
use Modules\Dormitory\Application\Facility\ManageRooms;
use Modules\Dormitory\Application\Facility\RoomData;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\CapacityBasis;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Facility\RoomComposition;
use Modules\Dormitory\Domain\Facility\UnavailableReason;
use Modules\Dormitory\Domain\Facility\YearEndPolicy;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Kamar asrama (PRD-008 §4.3–§4.4, §15 DM-1): tambah (dari detail asrama), detail kamar, ubah,
 * nonaktifkan/aktifkan kembali. Melihat: `dormitory.facilities.view`; mengelola: `dormitory.facilities.manage`
 * di node pemilik asrama.
 */
final class RoomController
{
    use HandlesFacilityActions;

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function store(Request $request, string $building, ManageRooms $rooms): RedirectResponse
    {
        $target = Str::isUuid($building) ? Building::query()->find($building) : null;

        if (! $target instanceof Building) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $target);

        $request->validate($this->rules(), $this->facilityMessages());

        $room = $this->attempt(fn (): Room => $rooms->create($this->tenantId(), $target->id, $this->data($request)));

        return $this->done("Kamar {$room->code} ditambahkan. Lanjutkan dengan menambah bed/loker di halaman kamar.");
    }

    public function show(string $room, FacilityDirectory $directory): Response
    {
        $target = $this->find($room, DormitoryAccess::FACILITIES_VIEW);

        return Inertia::render('dormitory/room', array_merge($directory->room($target), [
            'canManage' => $this->authorization->allows(DormitoryAccess::FACILITIES_MANAGE, $target->organization_id),
            'options' => [
                'bases' => CapacityBasis::options(),
                'compositions' => RoomComposition::options(),
                'policies' => YearEndPolicy::options(),
                'reasons' => UnavailableReason::options(),
                'supervisorSlotsMax' => ManageRooms::SUPERVISOR_SLOTS_MAX,
                'batchMax' => ManageRoomResources::BATCH_MAX,
                'noteMax' => ManageRoomResources::NOTE_MAX_LENGTH,
            ],
        ]));
    }

    public function update(Request $request, string $room, ManageRooms $rooms): RedirectResponse
    {
        $target = $this->find($room, DormitoryAccess::FACILITIES_MANAGE);

        $request->validate($this->rules(), $this->facilityMessages());

        $changed = $this->attempt(fn (): array => $rooms->update($this->tenantId(), $target->id, $this->data($request)));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Kamar disimpan.');
    }

    public function status(Request $request, string $room, ManageRooms $rooms): RedirectResponse
    {
        $target = $this->find($room, DormitoryAccess::FACILITIES_MANAGE);

        $request->validate(['active' => ['required', 'boolean']]);

        $updated = $this->attempt(fn (): Room => $rooms->setActive($this->tenantId(), $target->id, $request->boolean('active')));

        return $this->done($updated->is_active
            ? "Kamar {$updated->code} diaktifkan kembali."
            : "Kamar {$updated->code} dinonaktifkan. Kamar ini tidak menerima penghuni baru.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function find(string $roomId, string $permission): Room
    {
        $room = Str::isUuid($roomId) ? Room::query()->find($roomId) : null;

        if (! $room instanceof Room) {
            abort(404);
        }

        Gate::authorize($permission, $room);

        return $room;
    }

    private function data(Request $request): RoomData
    {
        $name = FacilityCode::cleanName($request->input('name'));
        $policy = $request->input('year_end_policy');

        return new RoomData(
            code: FacilityCode::clean($request->input('code')),
            name: $name === '' ? null : $name,
            capacityBasis: CapacityBasis::from($request->string('capacity_basis')->value()),
            composition: RoomComposition::from($request->string('composition')->value()),
            supervisorSlots: $request->integer('supervisor_slots'),
            yearEndPolicy: is_string($policy) && $policy !== '' ? YearEndPolicy::from($policy) : null,
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:'.ManageRooms::NAME_MAX_LENGTH],
            'capacity_basis' => ['required', 'string', Rule::enum(CapacityBasis::class)],
            'composition' => ['required', 'string', Rule::enum(RoomComposition::class)],
            'supervisor_slots' => ['nullable', 'integer', 'between:0,'.ManageRooms::SUPERVISOR_SLOTS_MAX],
            'year_end_policy' => ['nullable', 'string', Rule::enum(YearEndPolicy::class)],
        ];
    }
}
