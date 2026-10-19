<?php

namespace Modules\Academic\Application\Student;

use Modules\Core\Domain\Person\Gender;

/**
 * Isian "Ubah data diri" siswa (PRD-002 §4.5, OD-AC-09). Kolom opsional yang kosong (null) berarti
 * datanya dihapus.
 */
final readonly class StudentProfileData
{
    public function __construct(
        public string $name,
        public Gender $gender,
        public ?string $birthPlace,
        public ?string $birthDate,
        public ?string $phone,
        public ?string $address,
        public ?string $nisn,
    ) {}
}
