<?php

namespace Modules\Academic\Application\Subject;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Subject\Exceptions\SubjectException;
use Modules\Academic\Domain\Subject\SubjectGroup;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationType;

/**
 * Kelompok mata pelajaran per lembaga (PRD-002 §22.4 AC-8b): tambah, ubah, hapus.
 *
 * Aturan [ASUMSI disetujui owner 2026-11-10]:
 * - Nama wajib (maks. 60), unik per lembaga tanpa beda huruf besar/kecil.
 * - Urutan bilangan bulat 0–999 (kecil dulu); "ikut jumlah & rata-rata rapor" ya/tidak.
 * - Hapus hanya bila belum dipakai mapel.
 *
 * Hak akses (academic.classes.manage di lembaga kelompok) dicek pemanggil lewat Gate.
 */
final class ManageSubjectGroups
{
    public const NAME_MAX_LENGTH = 60;

    public const ORDER_MAX = 999;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws SubjectException
     */
    public function create(string $tenantId, string $organizationId, string $name, int $sortOrder, bool $countsInTotals): SubjectGroup
    {
        $name = self::assertValid($name, $sortOrder);

        return $this->transaction($name, function () use ($tenantId, $organizationId, $name, $sortOrder, $countsInTotals): SubjectGroup {
            $organization = Str::isUuid($organizationId)
                ? Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($organizationId)->first()
                : null;

            if (! $organization instanceof Organization || $organization->type !== OrganizationType::Lembaga) {
                throw SubjectException::make('organization_id', 'Lembaga tidak ditemukan di yayasan ini.');
            }

            $this->assertUnique($organization->id, $name, null);

            $group = new SubjectGroup;
            $group->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'name' => $name,
                'sort_order' => $sortOrder,
                'counts_in_totals' => $countsInTotals,
            ]);
            $group->save();

            $this->audit->record(
                AcademicAuditAction::SUBJECT_GROUP_CREATED,
                AcademicAuditAction::SUBJECT_SUBJECT_GROUP,
                $group->id,
                ['nama' => $group->name, 'lembaga' => $organization->code, 'ikut_rata_rata' => $countsInTotals],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $group;
        });
    }

    /**
     * @return list<string> field yang berubah
     *
     * @throws SubjectException
     */
    public function update(string $tenantId, string $groupId, string $name, int $sortOrder, bool $countsInTotals): array
    {
        $name = self::assertValid($name, $sortOrder);

        return $this->transaction($name, function () use ($tenantId, $groupId, $name, $sortOrder, $countsInTotals): array {
            $group = $this->lock($tenantId, $groupId);
            $this->assertUnique($group->organization_id, $name, $group->id);

            $group->fill(['name' => $name, 'sort_order' => $sortOrder, 'counts_in_totals' => $countsInTotals]);
            $changed = array_keys($group->getDirty());

            if ($changed === []) {
                return [];
            }

            $group->save();

            $this->audit->record(
                AcademicAuditAction::SUBJECT_GROUP_UPDATED,
                AcademicAuditAction::SUBJECT_SUBJECT_GROUP,
                $group->id,
                ['nama' => $group->name, 'field_diubah' => $changed],
                organizationId: $group->organization_id,
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * @throws SubjectException
     */
    public function delete(string $tenantId, string $groupId): string
    {
        return DB::transaction(function () use ($tenantId, $groupId): string {
            $group = $this->lock($tenantId, $groupId);

            if (DB::table('ac_subjects')->where('group_id', $group->id)->exists()) {
                throw SubjectException::make('form', "Kelompok {$group->name} masih dipakai mata pelajaran. Pindahkan mapelnya dulu.");
            }

            $name = $group->name;
            $group->delete();

            $this->audit->record(
                AcademicAuditAction::SUBJECT_GROUP_DELETED,
                AcademicAuditAction::SUBJECT_SUBJECT_GROUP,
                $group->id,
                ['nama' => $name],
                organizationId: $group->organization_id,
                tenantId: $tenantId,
            );

            return $name;
        });
    }

    public function find(string $tenantId, string $groupId): ?SubjectGroup
    {
        return Str::isUuid($groupId)
            ? SubjectGroup::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($groupId)->first()
            : null;
    }

    private static function assertValid(string $name, int $sortOrder): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw SubjectException::make('name', 'Nama kelompok wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        if ($sortOrder < 0 || $sortOrder > self::ORDER_MAX) {
            throw SubjectException::make('sort_order', 'Urutan harus angka 0–'.self::ORDER_MAX.'.');
        }

        return $name;
    }

    private function lock(string $tenantId, string $groupId): SubjectGroup
    {
        $group = Str::isUuid($groupId)
            ? SubjectGroup::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($groupId)->lockForUpdate()->first()
            : null;

        if (! $group instanceof SubjectGroup) {
            throw SubjectException::make('form', 'Kelompok mapel tidak ditemukan di yayasan ini.');
        }

        return $group;
    }

    private function assertUnique(string $organizationId, string $name, ?string $exceptId): void
    {
        $query = SubjectGroup::query()->withoutGlobalScope('tenant')->where('organization_id', $organizationId)->whereRaw('lower(name) = lower(?)', [$name]);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw SubjectException::make('name', "Kelompok {$name} sudah ada di lembaga ini.");
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function transaction(string $name, \Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            throw SubjectException::make('name', "Kelompok {$name} baru saja dibuat orang lain. Muat ulang halaman.");
        }
    }
}
