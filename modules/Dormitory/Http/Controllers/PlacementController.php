<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Domain\Person\Gender;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Placement\ManagePlacements;
use Modules\Dormitory\Application\Placement\PlacementInput;
use Modules\Dormitory\Application\Placement\ResidentEligibility;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\EndReason;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Penempatan penghuni (PRD-008 §16.4): cari calon, tempatkan/rencanakan, check-in, batalkan rencana, check-out,
 * ganti bed/loker. Butuh `dormitory.residents.manage` di node pemilik asrama (Gate dengan datanya: di luar cakupan
 * 404, tanpa izin 403). Semua aturan ada di ManagePlacements.
 */
final class PlacementController
{
    use HandlesFacilityActions;

    /**
     * Calon penghuni untuk pop-up Tempatkan (JSON). Orang yang tidak bisa dipilih tetap tampil dengan alasannya.
     */
    public function candidates(Request $request, string $room, ResidentEligibility $eligibility): JsonResponse
    {
        $target = $this->room($room);
        $category = PlacementCategory::tryFrom((string) $request->query('kategori')) ?? PlacementCategory::Resident;
        $query = mb_substr(trim((string) $request->query('q')), 0, 100);

        $tenantId = $this->tenantId();
        $owner = (string) Dormitory::query()->whereKey($target->dormitory_id)->value('organization_id');
        $candidates = $eligibility->search($tenantId, $owner, $category, $query);
        $ids = array_column($candidates, 'membership_id');
        $people = $eligibility->people($tenantId, $ids);
        $building = Building::query()->findOrFail($target->building_id);
        $holdings = [];

        foreach (DB::table('dm_placements as pl')
            ->join('dm_rooms as r', 'r.id', '=', 'pl.room_id')
            ->where('pl.tenant_id', $tenantId)
            ->whereIn('pl.membership_id', $ids)
            ->whereIn('pl.status', PlacementStatus::holdingValues())
            ->get(['pl.membership_id', 'pl.status', 'r.code']) as $row) {
            $holdings[(string) $row->membership_id][(string) $row->status] = (string) $row->code;
        }

        $result = [];

        foreach ($candidates as $candidate) {
            $id = $candidate['membership_id'];
            $person = $people[$id] ?? null;
            $holding = $holdings[$id] ?? [];

            $result[] = $candidate + [
                'active_in' => $holding[PlacementStatus::Active->value] ?? null,
                'planned_in' => $holding[PlacementStatus::Planned->value] ?? null,
                'blocked' => match (true) {
                    $person === null || $person['gender'] === null => 'Jenis kelamin belum diisi',
                    $person['gender'] !== $building->gender => $person['gender'] === Gender::Male ? 'Putra' : 'Putri',
                    isset($holding[PlacementStatus::Planned->value]) => 'Sudah punya rencana di '.$holding[PlacementStatus::Planned->value],
                    default => null,
                },
            ];
        }

        return response()->json(['candidates' => $result]);
    }

    public function store(Request $request, string $room, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->room($room);

        $request->validate([
            'membership_id' => ['required', 'uuid'],
            'category' => ['required', 'string', Rule::enum(PlacementCategory::class)],
            'mode' => ['required', 'string', Rule::in(['langsung', 'rencana'])],
            'bed_id' => ['nullable', 'uuid'],
            'locker_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:'.ManagePlacements::NOTE_MAX_LENGTH],
        ], [
            'membership_id.required' => 'Pilih orang yang ditempatkan.',
            'membership_id.uuid' => 'Pilih orang yang ditempatkan.',
        ]);

        $plan = $request->input('mode') === 'rencana';
        $placement = $this->attempt(fn (): Placement => $placements->place($this->tenantId(), $target->id, new PlacementInput(
            membershipId: $request->string('membership_id')->value(),
            category: PlacementCategory::from($request->string('category')->value()),
            plan: $plan,
            bedId: self::optional($request->input('bed_id')),
            lockerId: self::optional($request->input('locker_id')),
            date: self::optional($request->input('date')),
            note: self::optional($request->input('note')),
        ), $this->actorId()));

        return $this->done($plan
            ? "Rencana penempatan disimpan{$this->resourceText($placement)}."
            : "Penghuni ditempatkan{$this->resourceText($placement)}.");
    }

    public function checkIn(Request $request, string $placement, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->placement($placement);

        $request->validate([
            'date' => ['required', 'string', 'max:10'],
            'bed_id' => ['nullable', 'uuid'],
            'locker_id' => ['nullable', 'uuid'],
        ], ['date.required' => 'Isi tanggal masuk.']);

        $updated = $this->attempt(fn (): Placement => $placements->checkIn(
            $this->tenantId(),
            $target->id,
            $request->string('date')->value(),
            self::optional($request->input('bed_id')),
            self::optional($request->input('locker_id')),
            $this->actorId(),
        ));

        return $this->done("Check-in berhasil{$this->resourceText($updated)}.");
    }

    public function cancel(Request $request, string $placement, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->placement($placement);

        $request->validate(['reason' => ['required', 'string', 'max:'.ManagePlacements::NOTE_MAX_LENGTH]], ['reason.required' => 'Tuliskan alasan pembatalan.']);

        $this->attempt(fn (): Placement => $placements->cancel($this->tenantId(), $target->id, $request->string('reason')->value(), $this->actorId()));

        return $this->done('Rencana penempatan dibatalkan.');
    }

    public function checkOut(Request $request, string $placement, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->placement($placement);

        $request->validate([
            'date' => ['required', 'string', 'max:10'],
            'end_reason' => ['required', 'string', Rule::enum(EndReason::class)],
            'note' => ['nullable', 'string', 'max:'.ManagePlacements::NOTE_MAX_LENGTH],
        ], ['date.required' => 'Isi tanggal keluar.', 'end_reason.required' => 'Pilih alasan keluar.']);

        $this->attempt(fn (): Placement => $placements->checkOut(
            $this->tenantId(),
            $target->id,
            $request->string('date')->value(),
            EndReason::from($request->string('end_reason')->value()),
            self::optional($request->input('note')),
            $this->actorId(),
        ));

        return $this->done('Check-out berhasil. Bed/loker sudah kosong.');
    }

    public function resources(Request $request, string $placement, ManagePlacements $placements): RedirectResponse
    {
        $target = $this->placement($placement);

        $request->validate(['bed_id' => ['nullable', 'uuid'], 'locker_id' => ['nullable', 'uuid']]);

        $updated = $this->attempt(fn (): Placement => $placements->changeResources(
            $this->tenantId(),
            $target->id,
            self::optional($request->input('bed_id')),
            self::optional($request->input('locker_id')),
            $this->actorId(),
        ));

        return $this->done("Bed/loker disimpan{$this->resourceText($updated)}.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function room(string $roomId): Room
    {
        $room = Str::isUuid($roomId) ? Room::query()->find($roomId) : null;

        if (! $room instanceof Room) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::RESIDENTS_MANAGE, $room);

        return $room;
    }

    private function placement(string $placementId): Placement
    {
        $placement = Str::isUuid($placementId) ? Placement::query()->find($placementId) : null;

        if (! $placement instanceof Placement) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::RESIDENTS_MANAGE, $placement);

        return $placement;
    }

    private function resourceText(Placement $placement): string
    {
        $parts = array_filter([
            $placement->bed_id === null ? null : 'bed '.$placement->bed()->value('code'),
            $placement->locker_id === null ? null : 'loker '.$placement->locker()->value('code'),
        ]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
