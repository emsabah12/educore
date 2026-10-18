<?php

namespace Modules\Academic\Application\Student;

use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Core\Domain\Person\Gender;

/**
 * Satu baris orang tua/wali di form tambah siswa (PRD-002 §4.5). Isian sudah dirapikan.
 */
final readonly class GuardianData
{
    public function __construct(
        public GuardianRelation $relation,
        public string $name,
        public ?Gender $gender,
        public ?string $phone,
    ) {}
}
