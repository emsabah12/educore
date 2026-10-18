<?php

namespace Modules\Academic\Application\Student;

use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\Student;

/**
 * Hasil Tambah siswa, untuk pesan berhasil di halaman.
 */
final readonly class StudentCreated
{
    public function __construct(
        public Student $student,
        public Enrollment $enrollment,
        public string $name,
        public string $organizationName,
        public bool $newPerson,
        public int $guardiansLinked,
        public int $guardiansReused,
    ) {}
}
