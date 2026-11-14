<?php

namespace Modules\Dormitory\Application\Facility;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Person\Gender;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\CapacityBasis;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\ResourceKind;
use Modules\Dormitory\Domain\Facility\ResourceStatus;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Facility\RoomComposition;
use Modules\Dormitory\Domain\Facility\RoomResource;
use Modules\Dormitory\Domain\Facility\YearEndPolicy;

/**
 * Data halaman fasilitas asrama (PRD-008 §10, §15 DM-1). Penyaringan cakupan (node pemilik yang terlihat) dilakukan
 * di query, sebelum data dikirim ke browser.
 *
 * Kapasitas dihitung dari bed/loker (RoomCapacity). Ringkasan asrama hanya menjumlah kamar aktif di gedung aktif,
 * karena hanya kamar itu yang bisa menerima penghuni.
 *
 * @phpstan-type Capacity array{beds_total: int, beds_available: int, lockers_total: int, lockers_available: int, effective: int, supervisor_slots: int, resident_slots: int}
 * @phpstan-type RoomRow array{id: string, dormitory_id: string, building_id: string, code: string, name: string|null, capacity_basis: string, capacity_basis_label: string, composition: string, composition_label: string, supervisor_slots: int, year_end_policy: string, is_active: bool, capacity: Capacity}
 */
final class FacilityDirectory
{
    /**
     * Daftar asrama yang pemiliknya terlihat.
     *
     * @param  list<string>  $organizationIds  node yang terlihat (visibleNodeIds)
     * @return list<array{id: string, code: string, name: string, organization: string, organization_id: string, is_active: bool, buildings: int, rooms: int, effective: int, resident_slots: int, supervisor_slots: int}>
     */
    public function dormitories(string $tenantId, array $organizationIds, bool $includeInactive, ?string $organizationFilter): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $dormitories = Dormitory::query()->withoutGlobalScope('tenant')
            ->with('organization:id,name')
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $organizationIds)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->when($organizationFilter !== null, fn ($query) => $query->where('organization_id', $organizationFilter))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($dormitories->isEmpty()) {
            return [];
        }

        $ids = $dormitories->modelKeys();
        $buildingCounts = DB::table('dm_buildings')->whereIn('dormitory_id', $ids)->where('is_active', true)
            ->groupBy('dormitory_id')->selectRaw('dormitory_id, count(*) as total')->pluck('total', 'dormitory_id');

        $totals = [];

        foreach ($this->roomRows(fn (Builder $query) => $query
            ->whereIn('r.dormitory_id', $ids)
            ->where('r.is_active', true)
            ->whereExists(fn (Builder $building) => $building->from('dm_buildings as b')->whereColumn('b.id', 'r.building_id')->where('b.is_active', true))
        ) as $room) {
            $dormitoryId = $room['dormitory_id'];
            $totals[$dormitoryId] ??= ['rooms' => 0, 'effective' => 0, 'resident_slots' => 0, 'supervisor_slots' => 0];
            $totals[$dormitoryId]['rooms']++;
            $totals[$dormitoryId]['effective'] += $room['capacity']['effective'];
            $totals[$dormitoryId]['resident_slots'] += $room['capacity']['resident_slots'];
            $totals[$dormitoryId]['supervisor_slots'] += min($room['capacity']['supervisor_slots'], $room['capacity']['effective']);
        }

        $rows = [];

        foreach ($dormitories as $dormitory) {
            $total = $totals[$dormitory->id] ?? ['rooms' => 0, 'effective' => 0, 'resident_slots' => 0, 'supervisor_slots' => 0];
            $rows[] = [
                'id' => $dormitory->id,
                'code' => $dormitory->code,
                'name' => $dormitory->name,
                'organization' => $dormitory->organization->name,
                'organization_id' => $dormitory->organization_id,
                'is_active' => $dormitory->is_active,
                'buildings' => (int) ($buildingCounts[$dormitory->id] ?? 0),
                'rooms' => $total['rooms'],
                'effective' => $total['effective'],
                'resident_slots' => $total['resident_slots'],
                'supervisor_slots' => $total['supervisor_slots'],
            ];
        }

        return $rows;
    }

    /**
     * Node yang boleh menjadi pemilik asrama baru: LEMBAGA/UNIT aktif di dalam cakupan kelola.
     *
     * @param  list<string>  $organizationIds
     * @return list<array{value: string, label: string}>
     */
    public function ownerOptions(string $tenantId, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $options = [];

        foreach (Organization::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $organizationIds)
            ->whereIn('type', [OrganizationType::Lembaga->value, OrganizationType::Unit->value])
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name']) as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    /**
     * Pemilik yang muncul di saringan daftar: pemilik asrama yang terlihat.
     *
     * @param  list<string>  $organizationIds
     * @return list<array{value: string, label: string}>
     */
    public function ownerFilterOptions(string $tenantId, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $options = [];

        foreach (Organization::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', Dormitory::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereIn('organization_id', $organizationIds)->select('organization_id'))
            ->orderBy('name')
            ->get(['id', 'name']) as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    /**
     * Detail asrama: gedung beserta kamarnya (aktif & nonaktif).
     *
     * @return array{dormitory: array{id: string, code: string, name: string, organization: string, is_active: bool, default_year_end_policy: string}, buildings: list<array{id: string, code: string, name: string, gender: string, gender_label: string, is_active: bool, rooms: list<RoomRow>}>}
     */
    public function dormitory(Dormitory $dormitory): array
    {
        $rooms = [];

        foreach ($this->roomRows(fn (Builder $query) => $query->where('r.dormitory_id', $dormitory->id)) as $room) {
            $rooms[$room['building_id']][] = $room;
        }

        $buildings = [];

        foreach (Building::query()->withoutGlobalScope('tenant')
            ->where('dormitory_id', $dormitory->id)
            ->orderBy('code')
            ->get() as $building) {
            $buildings[] = [
                'id' => $building->id,
                'code' => $building->code,
                'name' => $building->name,
                'gender' => $building->gender->value,
                'gender_label' => self::genderLabel($building->gender),
                'is_active' => $building->is_active,
                'rooms' => $rooms[$building->id] ?? [],
            ];
        }

        return [
            'dormitory' => [
                'id' => $dormitory->id,
                'code' => $dormitory->code,
                'name' => $dormitory->name,
                'organization' => (string) Organization::query()->withoutGlobalScope('tenant')->whereKey($dormitory->organization_id)->value('name'),
                'is_active' => $dormitory->is_active,
                'default_year_end_policy' => $dormitory->default_year_end_policy->value,
            ],
            'buildings' => $buildings,
        ];
    }

    /**
     * Detail kamar: ringkasan kapasitas + daftar bed & loker.
     *
     * @return array{room: array{id: string, code: string, name: string|null, capacity_basis: string, capacity_basis_label: string, composition: string, composition_label: string, supervisor_slots: int, year_end_policy: string, is_active: bool, accepts_changes: bool, building: array{id: string, code: string, name: string, gender: string, gender_label: string, is_active: bool}, dormitory: array{id: string, code: string, name: string, organization: string, is_active: bool}}, capacity: Capacity, beds: list<array{id: string, code: string, status: string, status_label: string, unavailable_reason: string|null, unavailable_reason_label: string|null, unavailable_note: string|null, is_active: bool}>, lockers: list<array{id: string, code: string, status: string, status_label: string, unavailable_reason: string|null, unavailable_reason_label: string|null, unavailable_note: string|null, is_active: bool}>}
     */
    public function room(Room $room): array
    {
        $building = Building::query()->withoutGlobalScope('tenant')->whereKey($room->building_id)->firstOrFail();
        $dormitory = Dormitory::query()->withoutGlobalScope('tenant')->whereKey($room->dormitory_id)->firstOrFail();

        return [
            'room' => [
                'id' => $room->id,
                'code' => $room->code,
                'name' => $room->name,
                'capacity_basis' => $room->capacity_basis->value,
                'capacity_basis_label' => $room->capacity_basis->label(),
                'composition' => $room->composition->value,
                'composition_label' => $room->composition->label(),
                'supervisor_slots' => $room->supervisor_slots,
                'year_end_policy' => $room->year_end_policy->value,
                'is_active' => $room->is_active,
                // Bed/loker baru & aktifkan kembali hanya bila kamar, gedung, dan asrama aktif.
                'accepts_changes' => $room->is_active && $building->is_active && $dormitory->is_active,
                'building' => [
                    'id' => $building->id,
                    'code' => $building->code,
                    'name' => $building->name,
                    'gender' => $building->gender->value,
                    'gender_label' => self::genderLabel($building->gender),
                    'is_active' => $building->is_active,
                ],
                'dormitory' => [
                    'id' => $dormitory->id,
                    'code' => $dormitory->code,
                    'name' => $dormitory->name,
                    'organization' => (string) Organization::query()->withoutGlobalScope('tenant')->whereKey($dormitory->organization_id)->value('name'),
                    'is_active' => $dormitory->is_active,
                ],
            ],
            'capacity' => RoomCapacity::forRoom($room),
            'beds' => $this->resources(ResourceKind::Bed, $room->id),
            'lockers' => $this->resources(ResourceKind::Locker, $room->id),
        ];
    }

    public static function genderLabel(Gender $gender): string
    {
        return $gender === Gender::Male ? 'Putra' : 'Putri';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function genderOptions(): array
    {
        return [
            ['value' => Gender::Male->value, 'label' => 'Putra'],
            ['value' => Gender::Female->value, 'label' => 'Putri'],
        ];
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Kamar + jumlah bed/loker (aktif & tersedia) dalam satu query.
     *
     * @param  callable(Builder): Builder  $scope
     * @return list<RoomRow>
     */
    private function roomRows(callable $scope): array
    {
        $count = fn (string $table, bool $availableOnly) => DB::table($table.' as x')
            ->whereColumn('x.room_id', 'r.id')
            ->where('x.is_active', true)
            ->when($availableOnly, fn (Builder $query) => $query->where('x.status', ResourceStatus::Available->value))
            ->selectRaw('count(*)');

        $query = DB::table('dm_rooms as r')
            ->select('r.id', 'r.dormitory_id', 'r.building_id', 'r.code', 'r.name', 'r.capacity_basis', 'r.composition', 'r.supervisor_slots', 'r.year_end_policy', 'r.is_active')
            ->selectSub($count('dm_beds', false), 'beds_total')
            ->selectSub($count('dm_beds', true), 'beds_available')
            ->selectSub($count('dm_lockers', false), 'lockers_total')
            ->selectSub($count('dm_lockers', true), 'lockers_available')
            ->orderBy('r.code')
            ->orderBy('r.id');

        $rows = [];

        foreach ($scope($query)->get() as $row) {
            $basis = CapacityBasis::from((string) $row->capacity_basis);
            $composition = RoomComposition::from((string) $row->composition);

            $rows[] = [
                'id' => (string) $row->id,
                'dormitory_id' => (string) $row->dormitory_id,
                'building_id' => (string) $row->building_id,
                'code' => (string) $row->code,
                'name' => $row->name === null ? null : (string) $row->name,
                'capacity_basis' => $basis->value,
                'capacity_basis_label' => $basis->label(),
                'composition' => $composition->value,
                'composition_label' => $composition->label(),
                'supervisor_slots' => (int) $row->supervisor_slots,
                'year_end_policy' => YearEndPolicy::from((string) $row->year_end_policy)->value,
                'is_active' => (bool) $row->is_active,
                'capacity' => RoomCapacity::summarize(
                    $basis,
                    (int) $row->beds_total,
                    (int) $row->beds_available,
                    (int) $row->lockers_total,
                    (int) $row->lockers_available,
                    (int) $row->supervisor_slots,
                ),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, code: string, status: string, status_label: string, unavailable_reason: string|null, unavailable_reason_label: string|null, unavailable_note: string|null, is_active: bool}>
     */
    private function resources(ResourceKind $kind, string $roomId): array
    {
        $model = $kind->modelClass();
        $rows = [];

        /** @var RoomResource $resource */
        foreach ($model::query()->withoutGlobalScope('tenant')->where('room_id', $roomId)->orderByDesc('is_active')->orderBy('code')->get() as $resource) {
            $rows[] = [
                'id' => $resource->id,
                'code' => $resource->code,
                'status' => $resource->status->value,
                'status_label' => $resource->status->label(),
                'unavailable_reason' => $resource->unavailable_reason?->value,
                'unavailable_reason_label' => $resource->unavailable_reason?->label(),
                'unavailable_note' => $resource->unavailable_note,
                'is_active' => $resource->is_active,
            ];
        }

        return $rows;
    }
}
