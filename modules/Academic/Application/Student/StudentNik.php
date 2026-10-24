<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Domain\Tenancy\MembershipStatus;

/**
 * NIK siswa & orang tua/wali di modul Academic (PRD-002 §4.5, OD-AC-02 revisi 2026-10-23).
 * Penyimpanan & aturan dasar ada di Core PersonNik; di sini hanya hubungannya dengan yayasan.
 *
 * Pemilik NIK hanya disebut bila ia anggota yayasan yang sama, agar keberadaan orang di yayasan
 * lain tidak bocor. [ASUMSI, disetujui owner 2026-10-23]
 */
final class StudentNik
{
    public function __construct(
        private readonly PersonNik $personNik,
    ) {}

    /**
     * Pemilik NIK ini di yayasan ini (keanggotaan aktif), atau null. $ownerPersonId diisi bila NIK
     * sudah milik seseorang (di yayasan mana pun).
     *
     * @return array{membership_id: string, person_id: string, name: string}|null
     */
    public function memberOwning(string $tenantId, string $nik, ?string &$ownerPersonId = null): ?array
    {
        $ownerPersonId = $this->personNik->ownerOf($nik);

        if ($ownerPersonId === null) {
            return null;
        }

        $row = DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.person_id', $ownerPersonId)
            ->where('m.status', MembershipStatus::Active->value)
            ->first(['m.id', 'p.name']);

        return $row === null ? null : [
            'membership_id' => (string) $row->id,
            'person_id' => $ownerPersonId,
            'name' => (string) $row->name,
        ];
    }

    public function takenMessage(string $tenantId, string $ownerPersonId): string
    {
        $name = $ownerPersonId === '' ? null : DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.person_id', $ownerPersonId)
            ->value('p.name');

        return $name === null
            ? 'NIK ini sudah terdaftar di EduCore atas nama orang lain. Periksa kembali; bila NIK benar, hubungi admin platform.'
            : "NIK ini sudah tercatat atas nama {$name} di yayasan ini.";
    }
}
