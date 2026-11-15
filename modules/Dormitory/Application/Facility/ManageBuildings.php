<?php

namespace Modules\Dormitory\Application\Facility;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Person\Gender;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Exceptions\FacilityException;
use Modules\Dormitory\Domain\Placement\PlacementStatus;

/**
 * Gedung asrama (PRD-008 §4.1–§4.2, §15 DM-1): tambah, ubah, nonaktifkan/aktifkan kembali.
 *
 * Aturan:
 * - Gedung selalu di dalam satu asrama dan ikut pemiliknya (salinan `organization_id`, dijaga FK gabungan).
 * - Jenis kelamin Putra (L) / Putri (P) wajib; kamar di dalamnya mengikuti. Penggantian ditolak bila masih ada
 *   penempatan Aktif/Direncanakan di gedung itu (DM-2).
 * - Kode unik per asrama; nama wajib, maks. 100.
 * - Gedung baru hanya di asrama aktif; aktifkan kembali butuh asrama aktif.
 */
final class ManageBuildings
{
    public const NAME_MAX_LENGTH = 100;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws FacilityException
     */
    public function create(string $tenantId, string $dormitoryId, string $code, string $name, Gender $gender): Building
    {
        self::assertValid($code, $name);

        return DB::transaction(function () use ($tenantId, $dormitoryId, $code, $name, $gender): Building {
            $dormitory = Dormitory::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereKey($dormitoryId)
                ->lockForUpdate()
                ->first();

            if (! $dormitory instanceof Dormitory) {
                throw FacilityException::make('form', 'Asrama tidak ditemukan.');
            }

            if (! $dormitory->is_active) {
                throw FacilityException::make('form', "Asrama {$dormitory->name} nonaktif. Aktifkan dulu sebelum menambah gedung.");
            }

            $this->assertCodeFree($dormitory->id, $code, null);

            $building = new Building;
            $building->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $dormitory->organization_id,
                'dormitory_id' => $dormitory->id,
                'code' => $code,
                'name' => $name,
                'gender' => $gender,
            ]);
            $building->save();

            $this->audit->record(
                DormitoryAuditAction::BUILDING_CREATED,
                DormitoryAuditAction::SUBJECT_BUILDING,
                $building->id,
                ['asrama' => $dormitory->code, 'kode' => $building->code, 'nama' => $building->name, 'jenis_kelamin' => $gender->value],
                organizationId: $dormitory->organization_id,
                tenantId: $tenantId,
            );

            return $building;
        });
    }

    /**
     * @return list<string> field yang berubah
     *
     * @throws FacilityException
     */
    public function update(string $tenantId, string $buildingId, string $code, string $name, Gender $gender): array
    {
        self::assertValid($code, $name);

        return DB::transaction(function () use ($tenantId, $buildingId, $code, $name, $gender): array {
            $building = $this->lock($tenantId, $buildingId);
            $this->assertCodeFree($building->dormitory_id, $code, $building->id);

            $building->fill(['code' => $code, 'name' => $name, 'gender' => $gender]);
            $changed = array_keys($building->getDirty());

            // DM-2 (PRD-008 §16.5): penghuni yang ada tidak boleh tiba-tiba "salah gedung".
            if (in_array('gender', $changed, true) && DB::table('dm_placements as pl')
                ->join('dm_rooms as r', 'r.id', '=', 'pl.room_id')
                ->where('r.building_id', $building->id)
                ->whereIn('pl.status', PlacementStatus::holdingValues())
                ->exists()) {
                throw FacilityException::make('gender', 'Gedung ini masih punya penghuni atau rencana penempatan. Kosongkan dulu sebelum mengganti putra/putri.');
            }

            if ($changed === []) {
                return [];
            }

            $building->save();

            $this->audit->record(
                DormitoryAuditAction::BUILDING_UPDATED,
                DormitoryAuditAction::SUBJECT_BUILDING,
                $building->id,
                ['kode' => $building->code, 'field_diubah' => $changed],
                organizationId: $building->organization_id,
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * @throws FacilityException
     */
    public function setActive(string $tenantId, string $buildingId, bool $active): Building
    {
        return DB::transaction(function () use ($tenantId, $buildingId, $active): Building {
            $building = $this->lock($tenantId, $buildingId);

            if ($building->is_active === $active) {
                return $building;
            }

            if ($active && ! (bool) Dormitory::query()->withoutGlobalScope('tenant')->whereKey($building->dormitory_id)->value('is_active')) {
                throw FacilityException::make('form', 'Asramanya nonaktif. Aktifkan asrama dulu.');
            }

            $building->is_active = $active;
            $building->save();

            $this->audit->record(
                $active ? DormitoryAuditAction::BUILDING_REACTIVATED : DormitoryAuditAction::BUILDING_DEACTIVATED,
                DormitoryAuditAction::SUBJECT_BUILDING,
                $building->id,
                ['kode' => $building->code],
                organizationId: $building->organization_id,
                tenantId: $tenantId,
            );

            return $building;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @throws FacilityException
     */
    private static function assertValid(string $code, string $name): void
    {
        FacilityCode::assertValid($code);

        if ($name === '') {
            throw FacilityException::make('name', 'Nama gedung wajib diisi.');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw FacilityException::make('name', 'Nama gedung paling panjang '.self::NAME_MAX_LENGTH.' karakter.');
        }
    }

    /**
     * @throws FacilityException
     */
    private function assertCodeFree(string $dormitoryId, string $code, ?string $exceptId): void
    {
        $taken = Building::query()->withoutGlobalScope('tenant')
            ->where('dormitory_id', $dormitoryId)
            ->where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw FacilityException::make('code', "Kode {$code} sudah dipakai gedung lain di asrama ini.");
        }
    }

    /**
     * @throws FacilityException
     */
    private function lock(string $tenantId, string $buildingId): Building
    {
        $building = Building::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($buildingId)
            ->lockForUpdate()
            ->first();

        if (! $building instanceof Building) {
            throw FacilityException::make('form', 'Gedung tidak ditemukan.');
        }

        return $building;
    }
}
