<?php

namespace Modules\Dormitory\Contracts;

/**
 * Kontrak publik Dormitory untuk modul lain (PRD-003 §20 FN-6, PRD-008 OD-DM-10): siapa santri yang tinggal di
 * asrama. Hanya santri (penempatan kategori Penghuni); pengawas tidak. Rencana penempatan tidak dihitung.
 *
 * "Tinggal pada tanggal d" = masuk ≤ d dan belum keluar pada d (keluar > d). Mutasi pada tanggal d: sudah di kamar
 * baru pada d, sehingga tetap terhitung tinggal.
 */
interface DormitoryResidents
{
    /**
     * Keanggotaan (di antara $membershipIds) yang tinggal minimal satu hari dalam rentang [$from, $to] (Y-m-d,
     * inklusif) di asrama yang pemiliknya adalah lembaga $organizationId atau salah satu induknya (asrama yang
     * boleh dihuni santri lembaga itu, PRD-008 OD-DM-02).
     *
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    public function residentsDuring(string $tenantId, string $organizationId, array $membershipIds, string $from, string $to): array;
}
