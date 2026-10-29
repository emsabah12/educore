<?php

namespace Modules\Academic\Contracts;

/**
 * Kontrak publik Academic untuk modul lain (mis. Finance, PRD-003 §7): siswa berdasarkan PENDAFTARAN
 * di sebuah lembaga (PRD-002 §4). Tagihan & potongan melekat pada pendaftaran, bukan pada siswa.
 *
 * Hanya data dasar (tanpa data pribadi): nama, NISN, nomor induk, kelas berjalan.
 *
 * @phpstan-type EnrolledStudent array{enrollment_id: string, student_id: string, organization_id: string, name: string, nisn: string|null, student_number: string|null, class: string|null, active: bool}
 * @phpstan-type BillableEnrollment array{enrollment_id: string, name: string, start_date: string, end_date: string|null, class_id: string|null, class: string|null, grade: int|null}
 * @phpstan-type SchoolClassOption array{id: string, name: string, grade: int}
 */
interface EnrolledStudents
{
    /**
     * Pendaftaran TERBUKA di lembaga yang cocok dengan sebagian nama, atau awal NISN / nomor induk.
     * Urut nama, paling banyak $limit.
     *
     * @return list<EnrolledStudent>
     */
    public function search(string $tenantId, string $organizationId, string $query, int $limit): array;

    /**
     * Data pendaftaran (terbuka maupun sudah ditutup) milik yayasan ini; id lain diabaikan.
     *
     * @param  list<string>  $enrollmentIds
     * @return array<string, EnrolledStudent> id pendaftaran → data
     */
    public function describe(string $tenantId, array $enrollmentIds): array;

    /**
     * Pendaftaran di lembaga yang aktif pada sebagian rentang [$from, $to] (tanggal Y-m-d, inklusif),
     * beserta kelas & tingkatnya di tahun ajaran $academicYearId (keanggotaan kelas terbaru di tahun itu;
     * null bila belum masuk kelas). Dipakai Finance untuk membuat tagihan (PRD-003 §4). Urut nama.
     *
     * @return list<BillableEnrollment>
     */
    public function activeBetween(string $tenantId, string $organizationId, string $academicYearId, string $from, string $to): array;

    /**
     * Kelas aktif lembaga di tahun ajaran itu, urut tingkat lalu nama.
     *
     * @return list<SchoolClassOption>
     */
    public function classes(string $tenantId, string $organizationId, string $academicYearId): array;
}
