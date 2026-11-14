<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Facility\FacilityCode;
use Modules\Dormitory\Application\Facility\ManageRoomResources;
use Modules\Dormitory\Domain\Facility\ResourceKind;
use Modules\Dormitory\Domain\Facility\ResourceStatus;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Facility\RoomResource;
use Modules\Dormitory\Domain\Facility\UnavailableReason;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Bed & loker kamar (PRD-008 §4.4–§4.5, §15 DM-1): tambah banyak, ubah kode/status, nonaktifkan/aktifkan kembali,
 * hapus. Butuh `dormitory.facilities.manage` di node pemilik asrama.
 */
final class RoomResourceController
{
    use HandlesFacilityActions;

    public function store(Request $request, string $room, ManageRoomResources $resources): RedirectResponse
    {
        $target = Str::isUuid($room) ? Room::query()->find($room) : null;

        if (! $target instanceof Room) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $target);

        $request->validate([
            'kind' => ['required', 'string', Rule::enum(ResourceKind::class)],
            'prefix' => ['nullable', 'string', 'max:'.FacilityCode::MAX_LENGTH],
            'start' => ['required', 'integer', 'between:0,'.ManageRoomResources::START_MAX],
            'count' => ['required', 'integer', 'between:1,'.ManageRoomResources::BATCH_MAX],
        ], [
            'start.required' => 'Isi nomor mulai.',
            'count.required' => 'Isi jumlah.',
            'count.between' => 'Jumlah 1–'.ManageRoomResources::BATCH_MAX.' sekali tambah.',
        ]);

        $kind = ResourceKind::from($request->string('kind')->value());
        $codes = $this->attempt(fn (): array => $resources->addMany(
            $this->tenantId(),
            $target->id,
            $kind,
            (string) $request->input('prefix', ''),
            $request->integer('start'),
            $request->integer('count'),
        ));

        $range = count($codes) === 1 ? $codes[0] : $codes[0].' s.d. '.$codes[count($codes) - 1];

        return $this->done(count($codes).' '.mb_strtolower($kind->label())." ditambahkan ({$range}).");
    }

    public function update(Request $request, string $kind, string $resource, ManageRoomResources $resources): RedirectResponse
    {
        [$resourceKind, $target] = $this->find($kind, $resource);

        $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'status' => ['required', 'string', Rule::enum(ResourceStatus::class)],
            'unavailable_reason' => ['nullable', 'string', Rule::enum(UnavailableReason::class)],
            'unavailable_note' => ['nullable', 'string', 'max:'.ManageRoomResources::NOTE_MAX_LENGTH],
        ], $this->facilityMessages());

        $reason = $request->input('unavailable_reason');
        $note = $request->input('unavailable_note');

        $changed = $this->attempt(fn (): array => $resources->update(
            $this->tenantId(),
            $resourceKind,
            $target->id,
            FacilityCode::clean($request->input('code')),
            ResourceStatus::from($request->string('status')->value()),
            is_string($reason) && $reason !== '' ? UnavailableReason::from($reason) : null,
            is_string($note) ? trim($note) : null,
        ));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : "{$resourceKind->label()} disimpan.");
    }

    public function status(Request $request, string $kind, string $resource, ManageRoomResources $resources): RedirectResponse
    {
        [$resourceKind, $target] = $this->find($kind, $resource);

        $request->validate(['active' => ['required', 'boolean']]);

        $updated = $this->attempt(fn (): RoomResource => $resources->setActive($this->tenantId(), $resourceKind, $target->id, $request->boolean('active')));

        return $this->done($updated->is_active
            ? "{$resourceKind->label()} {$updated->code} diaktifkan kembali."
            : "{$resourceKind->label()} {$updated->code} dinonaktifkan dan tidak dihitung dalam kapasitas.");
    }

    public function destroy(string $kind, string $resource, ManageRoomResources $resources): RedirectResponse
    {
        [$resourceKind, $target] = $this->find($kind, $resource);

        $code = $this->attempt(fn (): string => $resources->delete($this->tenantId(), $resourceKind, $target->id));

        return $this->done("{$resourceKind->label()} {$code} dihapus.");
    }

    /**
     * @return array{ResourceKind, RoomResource}
     */
    private function find(string $kind, string $resourceId): array
    {
        $resourceKind = ResourceKind::tryFrom($kind);

        if ($resourceKind === null || ! Str::isUuid($resourceId)) {
            abort(404);
        }

        $model = $resourceKind->modelClass();
        $resource = $model::query()->find($resourceId);

        if (! $resource instanceof RoomResource) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $resource);

        return [$resourceKind, $resource];
    }
}
