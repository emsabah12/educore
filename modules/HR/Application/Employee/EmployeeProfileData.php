<?php

namespace Modules\HR\Application\Employee;

use Modules\Core\Domain\Person\Gender;
use Modules\HR\Domain\Employee\EducationLevel;

/**
 * Isian "Ubah profil" (PRD-001 §6). Berbeda dengan tambah pegawai, ini isian LENGKAP:
 * kolom yang dikosongkan berarti datanya dihapus. Teks sudah dipangkas; string kosong = null.
 */
final readonly class EmployeeProfileData
{
    public function __construct(
        public string $name,
        public Gender $gender,
        public ?string $birthPlace,
        public ?string $birthDate,
        public ?string $phone,
        public ?string $address,
        public ?string $employeeNumber,
        public ?string $nuptk,
        public ?EducationLevel $educationLevel,
        public ?string $educationMajor,
    ) {}
}
