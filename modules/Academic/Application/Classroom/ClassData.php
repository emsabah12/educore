<?php

namespace Modules\Academic\Application\Classroom;

/**
 * Isian kelas (PRD-002 §4.2): tingkat, nama rombel, wali kelas (opsional).
 */
final readonly class ClassData
{
    public function __construct(
        public int $grade,
        public string $name,
        public ?string $homeroomEmployeeId,
    ) {}

    /**
     * Nama rombel dengan spasi ganda dirapikan: "  VII   A " → "VII A".
     */
    public static function cleanName(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }
}
