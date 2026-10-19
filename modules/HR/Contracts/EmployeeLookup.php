<?php

namespace Modules\HR\Contracts;

/**
 * Kontrak publik HR untuk modul lain (ADR-001 §2.2): apakah seorang anggota yayasan punya profil
 * pegawai. Dipakai Academic agar data diri pegawai tidak diubah diam-diam dari halaman siswa
 * (PRD-002 §4.5, AC-1c-b2). Tidak membuka data kepegawaian apa pun.
 */
interface EmployeeLookup
{
    /**
     * Dari daftar membership, mana yang punya profil pegawai di yayasan ini (aktif maupun sudah berhenti).
     *
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    public function employeeMembershipIds(string $tenantId, array $membershipIds): array;
}
