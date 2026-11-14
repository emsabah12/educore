<?php

namespace Modules\Dormitory\Application\Facility;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Exceptions\FacilityException;
use Modules\Dormitory\Domain\Facility\YearEndPolicy;

/**
 * Asrama (PRD-008 §4.1, §15 DM-1): tambah, ubah, nonaktifkan/aktifkan kembali.
 *
 * Aturan:
 * - Pemilik = satu node LEMBAGA atau UNIT yang aktif di yayasan yang sama; BIRO ditolak (OD-DM-01).
 *   Pemilik tidak bisa diganti sesudah dibuat.
 * - Kode unik per yayasan (FacilityCode); nama wajib, maks. 100.
 * - Nonaktif = tidak menerima penempatan baru; riwayat tetap. Aktifkan kembali butuh pemilik yang aktif.
 *
 * Hak akses (dormitory.facilities.manage di node pemilik) dicek pemanggil lewat Gate.
 */
final class ManageDormitories
{
    public const NAME_MAX_LENGTH = 100;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws FacilityException
     */
    public function create(string $tenantId, string $organizationId, string $code, string $name, YearEndPolicy $policy): Dormitory
    {
        self::assertValid($code, $name);

        return DB::transaction(function () use ($tenantId, $organizationId, $code, $name, $policy): Dormitory {
            $owner = $this->owner($tenantId, $organizationId);
            $this->assertCodeFree($tenantId, $code, null);

            $dormitory = new Dormitory;
            $dormitory->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $owner->id,
                'code' => $code,
                'name' => $name,
                'default_year_end_policy' => $policy,
            ]);
            $dormitory->save();

            $this->audit->record(
                DormitoryAuditAction::DORMITORY_CREATED,
                DormitoryAuditAction::SUBJECT_DORMITORY,
                $dormitory->id,
                ['kode' => $dormitory->code, 'nama' => $dormitory->name, 'pemilik' => $owner->code],
                organizationId: $owner->id,
                tenantId: $tenantId,
            );

            return $dormitory;
        });
    }

    /**
     * @return list<string> field yang berubah
     *
     * @throws FacilityException
     */
    public function update(string $tenantId, string $dormitoryId, string $code, string $name, YearEndPolicy $policy): array
    {
        self::assertValid($code, $name);

        return DB::transaction(function () use ($tenantId, $dormitoryId, $code, $name, $policy): array {
            $dormitory = $this->lock($tenantId, $dormitoryId);
            $this->assertCodeFree($tenantId, $code, $dormitory->id);

            $dormitory->fill(['code' => $code, 'name' => $name, 'default_year_end_policy' => $policy]);
            $changed = array_keys($dormitory->getDirty());

            if ($changed === []) {
                return [];
            }

            $dormitory->save();

            $this->audit->record(
                DormitoryAuditAction::DORMITORY_UPDATED,
                DormitoryAuditAction::SUBJECT_DORMITORY,
                $dormitory->id,
                ['kode' => $dormitory->code, 'field_diubah' => $changed],
                organizationId: $dormitory->organization_id,
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * @throws FacilityException
     */
    public function setActive(string $tenantId, string $dormitoryId, bool $active): Dormitory
    {
        return DB::transaction(function () use ($tenantId, $dormitoryId, $active): Dormitory {
            $dormitory = $this->lock($tenantId, $dormitoryId);

            if ($dormitory->is_active === $active) {
                return $dormitory;
            }

            $owner = Organization::query()->withoutGlobalScope('tenant')->whereKey($dormitory->organization_id)->first();

            if ($active && (! $owner instanceof Organization || $owner->status !== OrganizationStatus::Active)) {
                throw FacilityException::make('form', 'Lembaga pemilik asrama ini nonaktif. Aktifkan lembaganya dulu.');
            }

            $dormitory->is_active = $active;
            $dormitory->save();

            $this->audit->record(
                $active ? DormitoryAuditAction::DORMITORY_REACTIVATED : DormitoryAuditAction::DORMITORY_DEACTIVATED,
                DormitoryAuditAction::SUBJECT_DORMITORY,
                $dormitory->id,
                ['kode' => $dormitory->code],
                organizationId: $dormitory->organization_id,
                tenantId: $tenantId,
            );

            return $dormitory;
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
            throw FacilityException::make('name', 'Nama asrama wajib diisi.');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw FacilityException::make('name', 'Nama asrama paling panjang '.self::NAME_MAX_LENGTH.' karakter.');
        }
    }

    /**
     * @throws FacilityException
     */
    private function owner(string $tenantId, string $organizationId): Organization
    {
        $organization = Organization::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($organizationId)
            ->first();

        if (! $organization instanceof Organization) {
            throw FacilityException::make('organization_id', 'Lembaga pemilik tidak ditemukan.');
        }

        if (! in_array($organization->type, [OrganizationType::Lembaga, OrganizationType::Unit], true)) {
            throw FacilityException::make('organization_id', 'Asrama hanya bisa dimiliki lembaga atau unit, bukan biro.');
        }

        if ($organization->status !== OrganizationStatus::Active) {
            throw FacilityException::make('organization_id', "{$organization->name} nonaktif, jadi tidak bisa memiliki asrama baru.");
        }

        return $organization;
    }

    /**
     * @throws FacilityException
     */
    private function assertCodeFree(string $tenantId, string $code, ?string $exceptId): void
    {
        $taken = Dormitory::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw FacilityException::make('code', "Kode {$code} sudah dipakai asrama lain.");
        }
    }

    /**
     * @throws FacilityException
     */
    private function lock(string $tenantId, string $dormitoryId): Dormitory
    {
        $dormitory = Dormitory::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($dormitoryId)
            ->lockForUpdate()
            ->first();

        if (! $dormitory instanceof Dormitory) {
            throw FacilityException::make('form', 'Asrama tidak ditemukan.');
        }

        return $dormitory;
    }
}
