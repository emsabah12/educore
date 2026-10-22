<?php

namespace Modules\Academic\Application\Grading;

/**
 * Isi aturan penilaian `academic.grading` (PRD-002 §19 AC-5a): KKM, komponen & bobot, batas predikat.
 *
 * Disimpan sebagai JSON: {"kkm": 75, "grade_a": 90, "grade_b": 80, "components": [{"code": "HARIAN",
 * "name": "Nilai harian", "weight": 40}, ...]}.
 *
 * Aturan:
 * - KKM 0–100; batas A > batas B > KKM, batas A ≤ 100;
 * - 1–6 komponen; kode huruf besar/angka/garis bawah, maks. 10, unik; nama maks. 50; bobot bulat
 *   1–100; jumlah bobot = 100.
 * - Predikat: A ≥ batas A, B ≥ batas B, C ≥ KKM, D < KKM.
 *
 * @phpstan-type Component array{code: string, name: string, weight: int}
 */
final readonly class GradingRules
{
    public const MAX_COMPONENTS = 6;

    public const CODE_PATTERN = '/^[A-Z0-9_]{1,10}$/';

    public const NAME_MAX_LENGTH = 50;

    /**
     * @param  list<Component>  $components
     */
    public function __construct(
        public int $kkm,
        public int $gradeA,
        public int $gradeB,
        public array $components,
    ) {}

    public static function defaults(): self
    {
        return new self(75, 90, 80, [
            ['code' => 'HARIAN', 'name' => 'Nilai harian', 'weight' => 40],
            ['code' => 'PTS', 'name' => 'Penilaian tengah semester', 'weight' => 30],
            ['code' => 'PAS', 'name' => 'Penilaian akhir semester', 'weight' => 30],
        ]);
    }

    /**
     * Baca nilai tersimpan; data yang tidak sah (mis. diubah langsung di database) → bawaan.
     */
    public static function fromValue(mixed $value): self
    {
        if (self::problem($value) !== null || ! is_array($value)) {
            return self::defaults();
        }

        $components = [];

        foreach ((array) $value['components'] as $component) {
            $component = (array) $component;
            $components[] = [
                'code' => (string) $component['code'],
                'name' => trim((string) $component['name']),
                'weight' => (int) $component['weight'],
            ];
        }

        return new self((int) $value['kkm'], (int) $value['grade_a'], (int) $value['grade_b'], $components);
    }

    /**
     * Pesan kesalahan pertama bila nilai aturan tidak sah, atau null bila sah.
     */
    public static function problem(mixed $value): ?string
    {
        if (! is_array($value)) {
            return 'format aturan penilaian tidak dikenal.';
        }

        foreach (['kkm' => 'KKM', 'grade_a' => 'Batas predikat A', 'grade_b' => 'Batas predikat B'] as $field => $label) {
            if (! self::isWhole($value[$field] ?? null) || (int) $value[$field] < 0 || (int) $value[$field] > 100) {
                return "{$label} harus bilangan bulat 0–100.";
            }
        }

        $kkm = (int) $value['kkm'];
        $gradeA = (int) $value['grade_a'];
        $gradeB = (int) $value['grade_b'];

        if (! ($gradeA > $gradeB && $gradeB > $kkm)) {
            return 'Batas predikat harus berurutan: batas A lebih tinggi dari batas B, dan batas B lebih tinggi dari KKM.';
        }

        $components = $value['components'] ?? null;

        if (! is_array($components) || ! array_is_list($components) || $components === [] || count($components) > self::MAX_COMPONENTS) {
            return 'Isi 1–'.self::MAX_COMPONENTS.' komponen nilai.';
        }

        $codes = [];
        $total = 0;

        foreach ($components as $index => $component) {
            $number = $index + 1;

            if (! is_array($component)) {
                return "Komponen ke-{$number} tidak lengkap.";
            }

            $code = $component['code'] ?? null;
            $name = is_string($component['name'] ?? null) ? trim($component['name']) : '';
            $weight = $component['weight'] ?? null;

            if (! is_string($code) || preg_match(self::CODE_PATTERN, $code) !== 1) {
                return "Kode komponen ke-{$number}: huruf besar, angka, atau garis bawah, maksimal 10 karakter.";
            }

            if (isset($codes[$code])) {
                return "Kode komponen {$code} dipakai lebih dari sekali.";
            }

            if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
                return "Nama komponen {$code} wajib diisi, maksimal ".self::NAME_MAX_LENGTH.' karakter.';
            }

            if (! self::isWhole($weight) || (int) $weight < 1 || (int) $weight > 100) {
                return "Bobot komponen {$code} harus bilangan bulat 1–100.";
            }

            $codes[$code] = true;
            $total += (int) $weight;
        }

        if ($total !== 100) {
            return "Jumlah bobot komponen harus 100 (sekarang {$total}).";
        }

        return null;
    }

    /**
     * @return array{kkm: int, grade_a: int, grade_b: int, components: list<Component>}
     */
    public function toArray(): array
    {
        return ['kkm' => $this->kkm, 'grade_a' => $this->gradeA, 'grade_b' => $this->gradeB, 'components' => $this->components];
    }

    public function predicate(int $score): string
    {
        return match (true) {
            $score >= $this->gradeA => 'A',
            $score >= $this->gradeB => 'B',
            $score >= $this->kkm => 'C',
            default => 'D',
        };
    }

    /** Ringkasan satu baris, mis. "KKM 75 · Harian 40%, PTS 30%, PAS 30%". */
    public function summary(): string
    {
        $parts = array_map(fn (array $component): string => "{$component['name']} {$component['weight']}%", $this->components);

        return "KKM {$this->kkm} · A ≥ {$this->gradeA}, B ≥ {$this->gradeB} · ".implode(', ', $parts);
    }

    /** Bilangan bulat, termasuk "75" dari form. */
    private static function isWhole(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1);
    }
}
