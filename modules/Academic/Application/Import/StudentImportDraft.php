<?php

namespace Modules\Academic\Application\Import;

use Modules\Academic\Application\Student\GuardianData;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\EntryType;
use Modules\Core\Domain\Person\Gender;

/**
 * Baris impor siswa yang sedang diperiksa (dipakai di dalam StudentImportValidator saja).
 */
final class StudentImportDraft
{
    /** @var list<array{column: string, label: string, message: string}> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public ?string $name = null;

    public ?Gender $gender = null;

    public ?string $birthPlace = null;

    public ?string $birthDate = null;

    public ?string $phone = null;

    public ?string $address = null;

    public ?string $nisn = null;

    public ?string $nik = null;

    public ?string $studentNumber = null;

    public ?string $startDate = null;

    public ?EntryType $entryType = null;

    public ?SchoolClass $class = null;

    /** Isian kelas mentah untuk ditampilkan bila tidak dikenal. */
    public ?string $classInput = null;

    /** @var list<GuardianData> */
    public array $guardians = [];

    public ?int $primaryGuardian = null;

    /**
     * Indeks di $guardians => kolom NIK-nya (untuk pesan per kolom).
     *
     * @var array<int, string>
     */
    public array $guardianNikColumns = [];

    /** Terisi bila NISN & nama cocok dengan siswa yang sudah tercatat (siswa lama). */
    public ?string $existingMembershipId = null;

    public ?string $existingStudentId = null;

    public function __construct(
        public readonly int $line,
    ) {}

    public function error(string $column, string $message): void
    {
        $this->errors[] = [
            'column' => $column,
            'label' => StudentImportColumns::label($column),
            'message' => $message,
        ];
    }
}
