<?php

namespace Modules\HR\Application\Employee;

use Modules\Core\Domain\Person\Gender;
use Modules\HR\Domain\Employee\EducationLevel;

/**
 * Isian "Tambah pegawai" (PRD-001 §6). Semua teks sudah dipangkas; string kosong = null.
 *
 * - $membershipId terisi → anggota yayasan yang sudah ada; $name diabaikan. Data pribadi yang
 *   diisi akan melengkapi/memperbarui Person-nya, yang dikosongkan tidak diubah.
 * - $membershipId kosong → orang baru: Person + Membership dibuat.
 * - $nik: NIK KTP/KK (sudah dirapikan). Wajib bila $requireNik dan orangnya belum punya NIK
 *   (form & impor: OD-HR-01 revisi 2026-10-23); data uji/lama boleh tanpa NIK.
 * - $extraOrganizationIds: lembaga penempatan tambahan (bukan utama), jabatan & tanggal mulai
 *   sama dengan penempatan utama. Dipakai impor kolom `lembaga_tambahan` (PRD-001 §6.2).
 */
final readonly class NewEmployeeData
{
    /**
     * @param  list<string>  $extraOrganizationIds
     */
    public function __construct(
        public string $tenantId,
        public ?string $membershipId,
        public ?string $name,
        public ?Gender $gender,
        public ?string $birthPlace,
        public ?string $birthDate,
        public ?string $phone,
        public ?string $address,
        public ?string $employeeNumber,
        public ?string $nuptk,
        public ?EducationLevel $educationLevel,
        public ?string $educationMajor,
        public string $employmentTypeId,
        public ?string $classificationId,
        public string $startDate,
        public string $organizationId,
        public string $positionId,
        public bool $confirmDuplicate = false,
        public ?string $nik = null,
        public array $extraOrganizationIds = [],
        public bool $requireNik = false,
    ) {}
}
