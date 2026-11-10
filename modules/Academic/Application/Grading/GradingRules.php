<?php

namespace Modules\Academic\Application\Grading;

/**
 * Isi aturan penilaian `academic.grading` (PRD-002 §19 AC-5a, diperluas §22 AC-8a): KKM, batas
 * predikat, dan aspek penilaian (mis. Tulis & Praktik) yang masing-masing punya komponen & bobot.
 *
 * Disimpan sebagai JSON: {"kkm": 75, "grade_a": 90, "grade_b": 80, "aspects": [{"code": "TULIS",
 * "name": "Pengetahuan", "components": [{"code": "UTS", "name": "Ujian tengah", "weight": 50}, ...]}]}.
 * Format lama tanpa aspek ({"components": [...]}) tetap sah dan dibaca sebagai satu aspek `NILAI`.
 *
 * Aturan:
 * - KKM 0–100; batas A > batas B > KKM, batas A ≤ 100;
 * - 1–3 aspek; kode aspek huruf besar/angka/garis bawah, maks. 10, unik; nama maks. 50;
 * - tiap aspek 1–6 komponen; kode komponen unik di seluruh aturan; nama maks. 50; bobot bulat
 *   1–100; jumlah bobot tiap aspek = 100.
 * - Predikat: A ≥ batas A, B ≥ batas B, C ≥ KKM, D < KKM (berlaku untuk nilai aspek & nilai akhir).
 *
 * @phpstan-type Component array{code: string, name: string, weight: int}
 * @phpstan-type Aspect array{code: string, name: string, components: list<Component>}
 * @phpstan-type RulesArray array{kkm: int, grade_a: int, grade_b: int, aspects: list<Aspect>}
 */
final readonly class GradingRules
{
    public const MAX_ASPECTS = 3;

    public const MAX_COMPONENTS = 6;

    public const CODE_PATTERN = '/^[A-Z0-9_]{1,10}$/';

    public const NAME_MAX_LENGTH = 50;

    /** Aspek tunggal untuk aturan lama/bawaan; tidak ditampilkan namanya. */
    public const DEFAULT_ASPECT_CODE = 'NILAI';

    public const DEFAULT_ASPECT_NAME = 'Nilai';

    /**
     * @param  list<Aspect>  $aspects
     */
    public function __construct(
        public int $kkm,
        public int $gradeA,
        public int $gradeB,
        public array $aspects,
    ) {}

    public static function defaults(): self
    {
        return new self(75, 90, 80, [[
            'code' => self::DEFAULT_ASPECT_CODE,
            'name' => self::DEFAULT_ASPECT_NAME,
            'components' => [
                ['code' => 'HARIAN', 'name' => 'Nilai harian', 'weight' => 40],
                ['code' => 'PTS', 'name' => 'Penilaian tengah semester', 'weight' => 30],
                ['code' => 'PAS', 'name' => 'Penilaian akhir semester', 'weight' => 30],
            ],
        ]]);
    }

    /**
     * Baca nilai tersimpan; data yang tidak sah (mis. diubah langsung di database) → bawaan.
     */
    public static function fromValue(mixed $value): self
    {
        if (self::problem($value) !== null || ! is_array($value)) {
            return self::defaults();
        }

        $aspects = [];

        foreach (self::rawAspects($value) as $aspect) {
            $components = [];

            foreach ((array) $aspect['components'] as $component) {
                $component = (array) $component;
                $components[] = [
                    'code' => (string) $component['code'],
                    'name' => trim((string) $component['name']),
                    'weight' => (int) $component['weight'],
                ];
            }

            $aspects[] = ['code' => (string) $aspect['code'], 'name' => trim((string) $aspect['name']), 'components' => $components];
        }

        return new self((int) $value['kkm'], (int) $value['grade_a'], (int) $value['grade_b'], $aspects);
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

        if (array_key_exists('aspects', $value)) {
            $aspects = $value['aspects'];

            if (! is_array($aspects) || ! array_is_list($aspects) || $aspects === [] || count($aspects) > self::MAX_ASPECTS) {
                return 'Isi 1–'.self::MAX_ASPECTS.' aspek penilaian.';
            }
        } else {
            $aspects = [['code' => self::DEFAULT_ASPECT_CODE, 'name' => self::DEFAULT_ASPECT_NAME, 'components' => $value['components'] ?? null]];
        }

        $multiple = count($aspects) > 1;
        $aspectCodes = [];
        $componentCodes = [];

        foreach ($aspects as $index => $aspect) {
            $number = $index + 1;

            if (! is_array($aspect)) {
                return "Aspek ke-{$number} tidak lengkap.";
            }

            $code = $aspect['code'] ?? null;
            $name = is_string($aspect['name'] ?? null) ? trim($aspect['name']) : '';

            if (! is_string($code) || preg_match(self::CODE_PATTERN, $code) !== 1) {
                return "Kode aspek ke-{$number}: huruf besar, angka, atau garis bawah, maksimal 10 karakter.";
            }

            if (isset($aspectCodes[$code])) {
                return "Kode aspek {$code} dipakai lebih dari sekali.";
            }

            if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
                return "Nama aspek {$code} wajib diisi, maksimal ".self::NAME_MAX_LENGTH.' karakter.';
            }

            $aspectCodes[$code] = true;
            $label = $multiple ? " aspek {$name}" : '';
            $components = $aspect['components'] ?? null;

            if (! is_array($components) || ! array_is_list($components) || $components === [] || count($components) > self::MAX_COMPONENTS) {
                return 'Isi 1–'.self::MAX_COMPONENTS." komponen nilai{$label}.";
            }

            $total = 0;

            foreach ($components as $componentIndex => $component) {
                $componentNumber = $componentIndex + 1;

                if (! is_array($component)) {
                    return "Komponen ke-{$componentNumber}{$label} tidak lengkap.";
                }

                $componentCode = $component['code'] ?? null;
                $componentName = is_string($component['name'] ?? null) ? trim($component['name']) : '';
                $weight = $component['weight'] ?? null;

                if (! is_string($componentCode) || preg_match(self::CODE_PATTERN, $componentCode) !== 1) {
                    return "Kode komponen ke-{$componentNumber}{$label}: huruf besar, angka, atau garis bawah, maksimal 10 karakter.";
                }

                if (isset($componentCodes[$componentCode])) {
                    return "Kode komponen {$componentCode} dipakai lebih dari sekali.";
                }

                if ($componentName === '' || mb_strlen($componentName) > self::NAME_MAX_LENGTH) {
                    return "Nama komponen {$componentCode} wajib diisi, maksimal ".self::NAME_MAX_LENGTH.' karakter.';
                }

                if (! self::isWhole($weight) || (int) $weight < 1 || (int) $weight > 100) {
                    return "Bobot komponen {$componentCode} harus bilangan bulat 1–100.";
                }

                $componentCodes[$componentCode] = true;
                $total += (int) $weight;
            }

            if ($total !== 100) {
                return "Jumlah bobot komponen{$label} harus 100 (sekarang {$total}).";
            }
        }

        return null;
    }

    /**
     * @return RulesArray
     */
    public function toArray(): array
    {
        return ['kkm' => $this->kkm, 'grade_a' => $this->gradeA, 'grade_b' => $this->gradeB, 'aspects' => $this->aspects];
    }

    /**
     * Semua komponen dari semua aspek, berurutan.
     *
     * @return list<Component>
     */
    public function components(): array
    {
        $components = [];

        foreach ($this->aspects as $aspect) {
            array_push($components, ...$aspect['components']);
        }

        return $components;
    }

    /**
     * @return list<string>
     */
    public function aspectCodes(): array
    {
        return array_column($this->aspects, 'code');
    }

    /**
     * Lebih dari satu aspek → nama aspek & nilai per aspek ditampilkan.
     */
    public function hasAspects(): bool
    {
        return count($this->aspects) > 1;
    }

    /**
     * Aturan yang hanya memuat aspek pilihan mapel (AC-8a), urutan mengikuti aturan. Pilihan kosong
     * (null) atau tidak ada yang cocok → semua aspek.
     *
     * @param  list<string>|null  $codes
     */
    public function forAspects(?array $codes): self
    {
        if ($codes === null || $codes === []) {
            return $this;
        }

        $kept = array_values(array_filter($this->aspects, fn (array $aspect): bool => in_array($aspect['code'], $codes, true)));

        return $kept === [] ? $this : new self($this->kkm, $this->gradeA, $this->gradeB, $kept);
    }

    public function predicate(int|float $score): string
    {
        return match (true) {
            $score >= $this->gradeA => 'A',
            $score >= $this->gradeB => 'B',
            $score >= $this->kkm => 'C',
            default => 'D',
        };
    }

    /** Ringkasan satu baris, mis. "KKM 75 · A ≥ 90, B ≥ 80 · Nilai harian 40%, PTS 30%, PAS 30%". */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->aspects as $aspect) {
            $components = implode(', ', array_map(fn (array $component): string => "{$component['name']} {$component['weight']}%", $aspect['components']));
            $parts[] = $this->hasAspects() ? "{$aspect['name']}: {$components}" : $components;
        }

        return "KKM {$this->kkm} · A ≥ {$this->gradeA}, B ≥ {$this->gradeB} · ".implode('; ', $parts);
    }

    /**
     * Aspek dari nilai yang sudah lolos problem(): format baru, atau format lama dibungkus satu aspek.
     *
     * @param  array<mixed>  $value
     * @return list<array<mixed>>
     */
    private static function rawAspects(array $value): array
    {
        if (array_key_exists('aspects', $value)) {
            return array_values(array_map(fn (mixed $aspect): array => (array) $aspect, (array) $value['aspects']));
        }

        return [['code' => self::DEFAULT_ASPECT_CODE, 'name' => self::DEFAULT_ASPECT_NAME, 'components' => $value['components']]];
    }

    /** Bilangan bulat, termasuk "75" dari form. */
    private static function isWhole(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1);
    }
}
