<?php

namespace Modules\HR\Contracts;

/**
 * Kontrak publik HR (PRD-002 §7): pegawai aktif yang punya penempatan berjalan di sebuah lembaga.
 * Dipakai Academic untuk memilih & memeriksa wali kelas. Hanya ID dan nama, tanpa data kepegawaian
 * lain. Penempatan dihitung di lembaga itu sendiri (bukan unit induknya).
 */
interface ActiveEmployees
{
    /**
     * Pegawai aktif dengan penempatan berjalan di $organizationId, urut nama.
     *
     * @return list<array{id: string, name: string}>
     */
    public function inOrganization(string $tenantId, string $organizationId): array;

    public function isActiveIn(string $tenantId, string $employeeId, string $organizationId): bool;

    /**
     * Nama pegawai (aktif maupun sudah berhenti) dan lembaga tempat ia masih aktif ditempatkan.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array{name: string, active_organization_ids: list<string>}>
     */
    public function describe(string $tenantId, array $employeeIds): array;

    /**
     * Pegawai berstatus aktif di seluruh yayasan (tanpa melihat lembaga penempatan) yang namanya cocok dengan
     * sebagian $query, urut nama, paling banyak $limit. Dipakai Dormitory untuk calon pengawas (PRD-008 §16.2,
     * OD-DM-03).
     *
     * @return list<array{membership_id: string, employee_id: string, name: string}>
     */
    public function searchInTenant(string $tenantId, string $query, int $limit): array;

    /**
     * Membership di antara $membershipIds yang punya profil pegawai berstatus aktif.
     *
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    public function activeMembershipIds(string $tenantId, array $membershipIds): array;
}
