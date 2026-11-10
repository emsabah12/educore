<?php

namespace Modules\Academic\Application\Subject;

/**
 * Isian mata pelajaran (PRD-002 §16 AC-3a): nama, kode singkat (opsional), rentang tingkat (opsional),
 * aspek penilaian yang dinilai (§22 AC-8a; hanya diubah bila `$setsAspects`), serta kelompok, urutan
 * & jenis nilai (§22.4 AC-8b; hanya diubah bila `$setsGrouping`).
 */
final readonly class SubjectData
{
    public function __construct(
        public string $name,
        public ?string $code,
        public ?int $gradeMin,
        public ?int $gradeMax,
        public bool $setsAspects = false,
        /** @var list<string> */
        public array $gradingAspects = [],
        public bool $setsGrouping = false,
        public ?string $groupId = null,
        public ?int $sortOrder = null,
        public string $gradeType = 'NUMBER',
    ) {}

    /**
     * Kode aspek dari form: huruf besar, tanpa spasi, tanpa duplikat.
     *
     * @return list<string>
     */
    public static function cleanAspects(mixed $value): array
    {
        $codes = [];

        foreach (is_array($value) ? $value : [] as $code) {
            if (is_string($code)) {
                $code = mb_strtoupper(trim($code));

                if ($code !== '' && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

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
