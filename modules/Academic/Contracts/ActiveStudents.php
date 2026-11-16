<?php

namespace Modules\Academic\Contracts;

/**
 * Kontrak publik Academic untuk modul lain (mis. Dormitory, PRD-008 §16.2): siswa yang punya pendaftaran TERBUKA
 * di salah satu lembaga yang diberikan, dikelompokkan per orang (Membership). Asrama menempatkan orang, bukan
 * pendaftaran, karena satu santri bisa terdaftar di beberapa lembaga sekaligus (PRD-002 §4.1).
 *
 * Hanya data dasar (tanpa data pribadi): nama, NISN, lembaga, nomor induk, kelas berjalan.
 *
 * @phpstan-type OpenEnrollment array{organization_id: string, organization: string, student_number: string|null, class: string|null}
 * @phpstan-type ActiveStudent array{membership_id: string, name: string, nisn: string|null, enrollments: list<OpenEnrollment>}
 */
interface ActiveStudents
{
    /**
     * Siswa dengan pendaftaran terbuka di salah satu $organizationIds yang cocok dengan sebagian nama, atau awal
     * NISN / nomor induk. Urut nama, paling banyak $limit orang. `enrollments` hanya pendaftaran terbuka di
     * $organizationIds.
     *
     * @param  list<string>  $organizationIds
     * @return list<ActiveStudent>
     */
    public function search(string $tenantId, array $organizationIds, string $query, int $limit): array;

    /**
     * Membership di antara $membershipIds yang masih punya pendaftaran terbuka di salah satu $organizationIds.
     *
     * @param  list<string>  $membershipIds
     * @param  list<string>  $organizationIds
     * @return list<string>
     */
    public function withOpenEnrollment(string $tenantId, array $membershipIds, array $organizationIds): array;

    /**
     * Data tampilan per orang (siswa aktif maupun yang sudah keluar): nama, NISN, dan semua pendaftaran terbuka.
     * Membership yang bukan siswa diabaikan.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, ActiveStudent> membership_id → data
     */
    public function describe(string $tenantId, array $membershipIds): array;

    /**
     * Cari santri berpendaftaran terbuka di $organizationIds menurut NISN atau NIS persis (impor penempatan asrama,
     * PRD-008 §20). NIS bisa sama di dua lembaga, jadi satu nilai bisa cocok ke beberapa orang.
     *
     * @param  list<string>  $organizationIds
     * @param  list<string>  $nisns
     * @param  list<string>  $studentNumbers
     * @return array{nisn: array<string, list<string>>, nis: array<string, list<string>>} nilai → membership_id
     */
    public function findByIdentifiers(string $tenantId, array $organizationIds, array $nisns, array $studentNumbers): array;
}
