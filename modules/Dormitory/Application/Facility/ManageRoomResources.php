<?php

namespace Modules\Dormitory\Application\Facility;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Exceptions\FacilityException;
use Modules\Dormitory\Domain\Facility\ResourceKind;
use Modules\Dormitory\Domain\Facility\ResourceStatus;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Facility\RoomResource;
use Modules\Dormitory\Domain\Facility\UnavailableReason;

/**
 * Bed & loker kamar (PRD-008 §4.4–§4.5, §15 DM-1).
 *
 * Aturan:
 * - Tambah banyak sekaligus: awalan + nomor mulai + jumlah (1–100), nomor diberi nol di depan (min. 2 digit),
 *   mis. awalan "A-101-" mulai 1 jumlah 10 → A-101-01 … A-101-10. Semua atau tidak: bila satu kode sudah ada,
 *   tidak ada yang disimpan. Maks. 200 bed/loker aktif per jenis per kamar.
 * - Ubah: kode, status Tersedia/Tidak tersedia. Tidak tersedia wajib alasan; alasan Lainnya wajib catatan.
 *   Bed/loker tidak tersedia tidak dihitung dalam kapasitas.
 * - Nonaktif = dikeluarkan dari inventaris (tidak dihitung, tidak bisa dipilih); riwayat tetap. Aktifkan kembali
 *   butuh kamar, gedung, dan asrama aktif.
 * - Hapus permanen hanya untuk bed/loker yang belum pernah dipakai penempatan. Penempatan baru ada di DM-2; sampai
 *   itu semua bed/loker belum pernah dipakai.
 * - Kamar baru menerima bed/loker bila kamar, gedung, dan asramanya aktif.
 */
final class ManageRoomResources
{
    public const BATCH_MAX = 100;

    public const START_MAX = 999;

    public const PER_ROOM_MAX = 200;

    public const NOTE_MAX_LENGTH = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<string> kode yang dibuat
     *
     * @throws FacilityException
     */
    public function addMany(string $tenantId, string $roomId, ResourceKind $kind, string $prefix, int $start, int $count): array
    {
        if ($count < 1 || $count > self::BATCH_MAX) {
            throw FacilityException::make('count', 'Jumlah 1–'.self::BATCH_MAX.' sekali tambah.');
        }

        if ($start < 0 || $start > self::START_MAX) {
            throw FacilityException::make('start', 'Nomor mulai 0–'.self::START_MAX.'.');
        }

        $codes = self::codes($prefix, $start, $count);

        return DB::transaction(function () use ($tenantId, $roomId, $kind, $codes): array {
            $room = $this->activeRoom($tenantId, $roomId);
            $model = $kind->modelClass();

            $existing = $model::query()->withoutGlobalScope('tenant')
                ->where('room_id', $room->id)
                ->whereIn('code', $codes)
                ->orderBy('code')
                ->pluck('code')
                ->all();

            if ($existing !== []) {
                $shown = implode(', ', array_slice($existing, 0, 5)).(count($existing) > 5 ? ', …' : '');

                throw FacilityException::make('prefix', "Kode {$shown} sudah ada di kamar ini. Ganti awalan atau nomor mulai.");
            }

            $activeCount = $model::query()->withoutGlobalScope('tenant')->where('room_id', $room->id)->where('is_active', true)->count();

            if ($activeCount + count($codes) > self::PER_ROOM_MAX) {
                throw FacilityException::make('count', 'Satu kamar paling banyak '.self::PER_ROOM_MAX.' '.mb_strtolower($kind->label()).' aktif.');
            }

            $now = now();
            $rows = [];

            foreach ($codes as $code) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'organization_id' => $room->organization_id,
                    'room_id' => $room->id,
                    'code' => $code,
                    'status' => ResourceStatus::Available->value,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table($kind->table())->insert($rows);

            $this->audit->record(
                DormitoryAuditAction::RESOURCES_ADDED,
                DormitoryAuditAction::SUBJECT_ROOM,
                $room->id,
                ['kamar' => $room->code, 'jenis' => $kind->value, 'jumlah' => count($codes), 'dari' => $codes[0], 'sampai' => $codes[count($codes) - 1]],
                organizationId: $room->organization_id,
                tenantId: $tenantId,
            );

            return $codes;
        });
    }

    /**
     * @return list<string> field yang berubah
     *
     * @throws FacilityException
     */
    public function update(string $tenantId, ResourceKind $kind, string $resourceId, string $code, ResourceStatus $status, ?UnavailableReason $reason, ?string $note): array
    {
        FacilityCode::assertValid($code);

        if ($status === ResourceStatus::Available) {
            $reason = null;
            $note = null;
        } elseif ($reason === null) {
            throw FacilityException::make('unavailable_reason', 'Pilih alasan kenapa tidak tersedia.');
        } elseif ($reason === UnavailableReason::Other && ($note === null || $note === '')) {
            throw FacilityException::make('unavailable_note', 'Tuliskan keterangan untuk alasan Lainnya.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw FacilityException::make('unavailable_note', 'Keterangan paling panjang '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $kind, $resourceId, $code, $status, $reason, $note): array {
            $resource = $this->lock($tenantId, $kind, $resourceId);
            $model = $kind->modelClass();

            if ($model::query()->withoutGlobalScope('tenant')->where('room_id', $resource->room_id)->where('code', $code)->whereKeyNot($resource->id)->exists()) {
                throw FacilityException::make('code', "Kode {$code} sudah ada di kamar ini.");
            }

            $resource->fill([
                'code' => $code,
                'status' => $status,
                'unavailable_reason' => $reason,
                'unavailable_note' => $note === '' ? null : $note,
            ]);
            $changed = array_keys($resource->getDirty());

            if ($changed === []) {
                return [];
            }

            $resource->save();

            $this->audit->record(
                DormitoryAuditAction::RESOURCE_UPDATED,
                self::subject($kind),
                $resource->id,
                ['kode' => $resource->code, 'status' => $resource->status->value, 'alasan' => $resource->unavailable_reason?->value, 'field_diubah' => $changed],
                organizationId: $resource->organization_id,
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * @throws FacilityException
     */
    public function setActive(string $tenantId, ResourceKind $kind, string $resourceId, bool $active): RoomResource
    {
        return DB::transaction(function () use ($tenantId, $kind, $resourceId, $active): RoomResource {
            $resource = $this->lock($tenantId, $kind, $resourceId);

            if ($resource->is_active === $active) {
                return $resource;
            }

            if ($active) {
                $this->activeRoom($tenantId, $resource->room_id);
            }

            $resource->is_active = $active;
            $resource->save();

            $this->audit->record(
                $active ? DormitoryAuditAction::RESOURCE_REACTIVATED : DormitoryAuditAction::RESOURCE_DEACTIVATED,
                self::subject($kind),
                $resource->id,
                ['kode' => $resource->code],
                organizationId: $resource->organization_id,
                tenantId: $tenantId,
            );

            return $resource;
        });
    }

    /**
     * @return string kode yang dihapus
     *
     * @throws FacilityException
     */
    public function delete(string $tenantId, ResourceKind $kind, string $resourceId): string
    {
        return DB::transaction(function () use ($tenantId, $kind, $resourceId): string {
            $resource = $this->lock($tenantId, $kind, $resourceId);
            $code = $resource->code;
            $resource->delete();

            $this->audit->record(
                DormitoryAuditAction::RESOURCE_DELETED,
                self::subject($kind),
                $resource->id,
                ['kode' => $code],
                organizationId: $resource->organization_id,
                tenantId: $tenantId,
            );

            return $code;
        });
    }

    /**
     * Kode hasil tambah banyak. Awalan dirapikan seperti kode (huruf besar, spasi → "-"), tanda "-" di akhir
     * dipertahankan.
     *
     * @return non-empty-list<string>
     *
     * @throws FacilityException
     */
    public static function codes(string $prefix, int $start, int $count): array
    {
        $cleanPrefix = mb_strtoupper(trim($prefix));
        $cleanPrefix = (string) preg_replace('/\s+/u', '-', $cleanPrefix);
        $cleanPrefix = ltrim((string) preg_replace('/-{2,}/', '-', $cleanPrefix), '-');
        if ($count < 1) {
            throw FacilityException::make('count', 'Jumlah minimal 1.');
        }

        $width = max(2, strlen((string) ($start + $count - 1)));
        $code = fn (int $number): string => $cleanPrefix.str_pad((string) $number, $width, '0', STR_PAD_LEFT);
        $codes = [$code($start)];

        for ($number = $start + 1; $number < $start + $count; $number++) {
            $codes[] = $code($number);
        }

        foreach ($codes as $candidate) {
            FacilityCode::assertValid($candidate, 'prefix');
        }

        return $codes;
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private static function subject(ResourceKind $kind): string
    {
        return $kind === ResourceKind::Bed ? DormitoryAuditAction::SUBJECT_BED : DormitoryAuditAction::SUBJECT_LOCKER;
    }

    /**
     * Kamar beserta gedung & asramanya harus aktif untuk menerima bed/loker.
     *
     * @throws FacilityException
     */
    private function activeRoom(string $tenantId, string $roomId): Room
    {
        $room = Room::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($roomId)
            ->lockForUpdate()
            ->first();

        if (! $room instanceof Room) {
            throw FacilityException::make('form', 'Kamar tidak ditemukan.');
        }

        $buildingActive = (bool) Building::query()->withoutGlobalScope('tenant')->whereKey($room->building_id)->value('is_active');
        $dormitoryActive = (bool) Dormitory::query()->withoutGlobalScope('tenant')->whereKey($room->dormitory_id)->value('is_active');

        if (! $room->is_active || ! $buildingActive || ! $dormitoryActive) {
            throw FacilityException::make('form', 'Kamar, gedung, atau asramanya nonaktif. Aktifkan dulu.');
        }

        return $room;
    }

    /**
     * @throws FacilityException
     */
    private function lock(string $tenantId, ResourceKind $kind, string $resourceId): RoomResource
    {
        $model = $kind->modelClass();
        $resource = $model::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($resourceId)
            ->lockForUpdate()
            ->first();

        if (! $resource instanceof RoomResource) {
            throw FacilityException::make('form', $kind->label().' tidak ditemukan.');
        }

        return $resource;
    }
}
