<?php

namespace Modules\Academic\Application\Subject;

/**
 * Isian mata pelajaran (PRD-002 §16 AC-3a): nama, kode singkat (opsional), rentang tingkat (opsional).
 */
final readonly class SubjectData
{
    public function __construct(
        public string $name,
        public ?string $code,
        public ?int $gradeMin,
        public ?int $gradeMax,
    ) {}

    /**
     * Nama dengan spasi ganda dirapikan: "  Bahasa   Arab " → "Bahasa Arab".
     */
    public static function cleanName(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }

    /**
     * Kode singkat tanpa spasi, huruf besar; kosong → null. " mtk " → "MTK".
     */
    public static function cleanCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = mb_strtoupper((string) preg_replace('/\s+/u', '', $value));

        return $code === '' ? null : $code;
    }

    public static function cleanGrade(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d{1,2}$/', $value) === 1 ? (int) $value : null;
    }
}
