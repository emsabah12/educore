<?php

namespace Modules\Academic\Application\Student;

use Modules\Academic\Domain\Student\EntryType;
use Modules\Core\Domain\Person\Gender;

/**
 * Isian form Tambah siswa (PRD-002 §6). Isian sudah dirapikan (StudentInput); kebenarannya
 * diperiksa CreateStudent.
 *
 * $existingMembershipId terisi = operator memilih "ini orang yang sama" dari daftar calon orang
 * ganda; $confirmNew = operator menyatakan orang berbeda.
 */
final readonly class NewStudentData
{
    /**
     * @param  list<GuardianData>  $guardians
     * @param  int|null  $primaryGuardian  indeks di $guardians yang menjadi kontak utama
     */
    public function __construct(
        public string $tenantId,
        public string $name,
        public ?Gender $gender,
        public ?string $birthPlace,
        public ?string $birthDate,
        public ?string $phone,
        public ?string $address,
        public ?string $nisn,
        public string $organizationId,
        public ?string $studentNumber,
        public string $startDate,
        public EntryType $entryType,
        public array $guardians = [],
        public ?int $primaryGuardian = null,
        public ?string $existingMembershipId = null,
        public bool $confirmNew = false,
    ) {}
}
