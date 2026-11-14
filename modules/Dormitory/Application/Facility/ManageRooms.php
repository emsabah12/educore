<?php

namespace Modules\Dormitory\Application\Facility;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Exceptions\FacilityException;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Facility\RoomComposition;

/**
 * Kamar asrama (PRD-008 §4.3–§4.4, §15 DM-1): tambah, ubah, nonaktifkan/aktifkan kembali.
 *
 * Aturan:
 * - Kamar selalu di satu gedung (dan asrama gedung itu), ikut pemilik asrama; tidak bisa dipindah.
 * - Kode unik per gedung; nama opsional, maks. 100.
 * - Komposisi Khusus santri ⇒ slot pengawas 0; Santri + pengawas ⇒ slot pengawas 1–5. Slot pengawas tidak boleh
 *   sama atau lebih dari kapasitas efektif bila kamar sudah punya kapasitas (harus tersisa slot santri).
 * - Kebijakan akhir tahun bawaan = milik asrama.
 * - Kamar baru hanya di gedung & asrama aktif; aktifkan kembali butuh gedung & asrama aktif.
 * - Dasar kapasitas, komposisi, dan gender gedung bebas diubah selama belum ada penempatan (penempatan dibangun
 *   di DM-2, yang menambah pengamannya).
 */
final class ManageRooms
{
    public const NAME_MAX_LENGTH = 100;

    public const SUPERVISOR_SLOTS_MAX = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws FacilityException
     */
    public function create(string $tenantId, string $buildingId, RoomData $data): Room
    {
        $slots = self::assertValid($data);

        return DB::transaction(function () use ($tenantId, $buildingId, $data, $slots): Room {
            $building = Building::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereKey($buildingId)
                ->lockForUpdate()
                ->first();

            if (! $building instanceof Building) {
                throw FacilityException::make('form', 'Gedung tidak ditemukan.');
            }

            $dormitory = Dormitory::query()->withoutGlobalScope('tenant')->whereKey($building->dormitory_id)->firstOrFail();

            if (! $building->is_active || ! $dormitory->is_active) {
                throw FacilityException::make('form', 'Gedung atau asramanya nonaktif. Aktifkan dulu sebelum menambah kamar.');
            }

            $this->assertCodeFree($building->id, $data->code, null);

            $room = new Room;
            $room->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $building->organization_id,
                'dormitory_id' => $building->dormitory_id,
                'building_id' => $building->id,
                'code' => $data->code,
                'name' => $data->name,
                'capacity_basis' => $data->capacityBasis,
                'composition' => $data->composition,
                'supervisor_slots' => $slots,
                'year_end_policy' => $data->yearEndPolicy ?? $dormitory->default_year_end_policy,
            ]);
            $room->save();

            $this->audit->record(
                DormitoryAuditAction::ROOM_CREATED,
                DormitoryAuditAction::SUBJECT_ROOM,
                $room->id,
                [
                    'asrama' => $dormitory->code,
                    'gedung' => $building->code,
                    'kode' => $room->code,
                    'dasar_kapasitas' => $room->capacity_basis->value,
                    'komposisi' => $room->composition->value,
                    'slot_pengawas' => $room->supervisor_slots,
                ],
                organizationId: $room->organization_id,
                tenantId: $tenantId,
            );

            return $room;
        });
    }

    /**
     * @return list<string> field yang berubah
     *
     * @throws FacilityException
     */
    public function update(string $tenantId, string $roomId, RoomData $data): array
    {
        $slots = self::assertValid($data);

        return DB::transaction(function () use ($tenantId, $roomId, $data, $slots): array {
            $room = $this->lock($tenantId, $roomId);
            $this->assertCodeFree($room->building_id, $data->code, $room->id);

            $room->fill([
                'code' => $data->code,
                'name' => $data->name,
                'capacity_basis' => $data->capacityBasis,
                'composition' => $data->composition,
                'supervisor_slots' => $slots,
            ]);

            if ($data->yearEndPolicy !== null) {
                $room->year_end_policy = $data->yearEndPolicy;
            }

            $changed = array_keys($room->getDirty());

            if ($changed === []) {
                return [];
            }

            $capacity = RoomCapacity::forRoom($room);

            if ($slots > 0 && $capacity['effective'] > 0 && $slots >= $capacity['effective']) {
                throw FacilityException::make('supervisor_slots', "Kapasitas kamar ini {$capacity['effective']}, jadi slot pengawas paling banyak ".($capacity['effective'] - 1).' agar masih ada tempat untuk santri.');
            }

            $room->save();

            $this->audit->record(
                DormitoryAuditAction::ROOM_UPDATED,
                DormitoryAuditAction::SUBJECT_ROOM,
                $room->id,
                ['kode' => $room->code, 'field_diubah' => $changed],
                organizationId: $room->organization_id,
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * @throws FacilityException
     */
    public function setActive(string $tenantId, string $roomId, bool $active): Room
    {
        return DB::transaction(function () use ($tenantId, $roomId, $active): Room {
            $room = $this->lock($tenantId, $roomId);

            if ($room->is_active === $active) {
                return $room;
            }

            if ($active) {
                $buildingActive = (bool) Building::query()->withoutGlobalScope('tenant')->whereKey($room->building_id)->value('is_active');
                $dormitoryActive = (bool) Dormitory::query()->withoutGlobalScope('tenant')->whereKey($room->dormitory_id)->value('is_active');

                if (! $buildingActive || ! $dormitoryActive) {
                    throw FacilityException::make('form', 'Gedung atau asramanya nonaktif. Aktifkan dulu.');
                }
            }

            $room->is_active = $active;
            $room->save();

            $this->audit->record(
                $active ? DormitoryAuditAction::ROOM_REACTIVATED : DormitoryAuditAction::ROOM_DEACTIVATED,
                DormitoryAuditAction::SUBJECT_ROOM,
                $room->id,
                ['kode' => $room->code],
                organizationId: $room->organization_id,
                tenantId: $tenantId,
            );

            return $room;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return int slot pengawas yang disimpan
     *
     * @throws FacilityException
     */
    private static function assertValid(RoomData $data): int
    {
        FacilityCode::assertValid($data->code);

        if ($data->name !== null && mb_strlen($data->name) > self::NAME_MAX_LENGTH) {
            throw FacilityException::make('name', 'Nama kamar paling panjang '.self::NAME_MAX_LENGTH.' karakter.');
        }

        if ($data->composition === RoomComposition::ResidentsOnly) {
            return 0;
        }

        if ($data->supervisorSlots < 1 || $data->supervisorSlots > self::SUPERVISOR_SLOTS_MAX) {
            throw FacilityException::make('supervisor_slots', 'Kamar santri + pengawas wajib punya 1–'.self::SUPERVISOR_SLOTS_MAX.' slot pengawas.');
        }

        return $data->supervisorSlots;
    }

    /**
     * @throws FacilityException
     */
    private function assertCodeFree(string $buildingId, string $code, ?string $exceptId): void
    {
        $taken = Room::query()->withoutGlobalScope('tenant')
            ->where('building_id', $buildingId)
            ->where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw FacilityException::make('code', "Kode {$code} sudah dipakai kamar lain di gedung ini.");
        }
    }

    /**
     * @throws FacilityException
     */
    private function lock(string $tenantId, string $roomId): Room
    {
        $room = Room::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($roomId)
            ->lockForUpdate()
            ->first();

        if (! $room instanceof Room) {
            throw FacilityException::make('form', 'Kamar tidak ditemukan.');
        }

        return $room;
    }
}
