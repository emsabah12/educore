<?php

namespace Modules\Academic\Application\Grading;

/**
 * Isi aturan penilaian `academic.grading` (PRD-002 §19 AC-5a, diperluas §22 AC-8a & AC-8b): KKM,
 * skala predikat, dan aspek penilaian (mis. Tulis & Praktik) yang masing-masing punya komponen & bobot.
 *
 * Disimpan sebagai JSON: {"kkm": 75, "predicates": [{"letter": "A", "label": "Sangat Baik", "min": 90},
 * ..., {"letter": "E", "label": "Perlu Perbaikan", "min": 0}], "aspects": [{"code": "TULIS",
 * "name": "Pengetahuan", "components": [{"code": "UTS", "name": "Ujian tengah", "weight": 50}, ...]}]}.
 * Format lama tetap sah: tanpa aspek ({"components": [...]}) dibaca sebagai satu aspek `NILAI`;
 * tanpa skala ({"grade_a": 90, "grade_b": 80}) dibaca sebagai A ≥ batas A, B ≥ batas B, C ≥ KKM, D.
 *
 * Aturan:
 * - KKM 0–100;
 * - skala predikat 2–6 tingkat urut dari tertinggi: huruf 1–2 huruf besar (unik), sebutan opsional
 *   (maks. 30), nilai minimal 0–100 menurun tegas, tingkat terakhir 0;
 * - 1–3 aspek; kode aspek huruf besar/angka/garis bawah, maks. 10, unik; nama maks. 50;
 * - tiap aspek 1–6 komponen; kode komponen unik di seluruh aturan; nama maks. 50; bobot bulat
 *   1–100; jumlah bobot tiap aspek = 100.
 * - Predikat = tingkat tertinggi yang nilai minimalnya terpenuhi (nilai aspek & nilai akhir); mapel
 *   bernilai huruf memilih langsung salah satu huruf skala.
 *
 * @phpstan-type Component array{code: string, name: string, weight: int}
 * @phpstan-type Aspect array{code: string, name: string, components: list<Component>}
 * @phpstan-type Level array{letter: string, label: string|null, min: int}
 * @phpstan-type RulesArray array{kkm: int, predicates: list<Level>, aspects: list<Aspect>}
 */
final readonly class GradingRules
{
    public const MAX_ASPECTS = 3;

    public const MAX_COMPONENTS = 6;

    public const MIN_LEVELS = 2;

    public const MAX_LEVELS = 6;

    public const LETTER_PATTERN = '/^[A-Z]{1,2}$/';

    public const LABEL_MAX_LENGTH = 30;

    public const CODE_PATTERN = '/^[A-Z0-9_]{1,10}$/';

    public const NAME_MAX_LENGTH = 50;

    /** Aspek tunggal untuk aturan lama/bawaan; tidak ditampilkan namanya. */
    public const DEFAULT_ASPECT_CODE = 'NILAI';

    public const DEFAULT_ASPECT_NAME = 'Nilai';

    /**
     * @param  list<Level>  $predicates  urut dari tertinggi, tingkat terakhir min 0
     * @param  list<Aspect>  $aspects
     */
    public function __construct(
        public int $kkm,
        public array $predicates,
        public array $aspects,
    ) {}

    /**
     * Skala lama A/B/C/D dari batas A, batas B, dan KKM (tanpa sebutan).
     *
     * @return list<Level>
     */
    public static function legacyScale(int $kkm, int $gradeA, int $gradeB): array
    {
        return [
            ['letter' => 'A', 'label' => null, 'min' => $gradeA],
            ['letter' => 'B', 'label' => null, 'min' => $gradeB],
            ['letter' => 'C', 'label' => null, 'min' => $kkm],
            ['letter' => 'D', 'label' => null, 'min' => 0],
        ];
    }

    public static function defaults(): self
    {
        return new self(75, self::legacyScale(75, 90, 80), [[
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

        if (array_key_exists('predicates', $value)) {
            $predicates = [];

            foreach ((array) $value['predicates'] as $level) {
                $level = (array) $level;
                $label = is_string($level['label'] ?? null) ? trim($level['label']) : '';
                $predicates[] = ['letter' => (string) $level['letter'], 'label' => $label === '' ? null : $label, 'min' => (int) $level['min']];
            }
        } else {
            $predicates = self::legacyScale((int) $value['kkm'], (int) $value['grade_a'], (int) $value['grade_b']);
        }

        return new self((int) $value['kkm'], $predicates, $aspects);
    }

    /**
     * Pesan kesalahan pertama bila nilai aturan tidak sah, atau null bila sah.
     */
    public static function problem(mixed $value): ?string
    {
        if (! is_array($value)) {
            return 'format aturan penilaian tidak dikenal.';
        }

        if (! self::isWhole($value['kkm'] ?? null) || (int) $value['kkm'] < 0 || (int) $value['kkm'] > 100) {
            return 'KKM harus bilangan bulat 0–100.';
        }

        $scaleProblem = array_key_exists('predicates', $value) ? self::scaleProblem($value['predicates']) : self::legacyScaleProblem($value);

        if ($scaleProblem !== null) {
            return $scaleProblem;
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
        return ['kkm' => $this->kkm, 'predicates' => $this->predicates, 'aspects' => $this->aspects];
    }

    /**
     * Huruf-huruf skala, urut dari tertinggi (pilihan nilai mapel bernilai huruf).
     *
     * @return list<string>
     */
    public function letters(): array
    {
        return array_column($this->predicates, 'letter');
    }

    /** Sebutan huruf skala (mis. "Sangat Baik"), atau null bila tidak ada. */
    public function labelOf(string $letter): ?string
    {
        foreach ($this->predicates as $level) {
            if ($level['letter'] === $letter) {
                return $level['label'];
            }
        }

        return null;
    }

    /** Ada tingkat yang bersebutan → rapor menulis sebutan & kotak keterangan predikat. */
    public function hasLabels(): bool
    {
        foreach ($this->predicates as $level) {
            if ($level['label'] !== null) {
                return true;
            }
        }

        return false;
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

        return $kept === [] ? $this : new self($this->kkm, $this->predicates, $kept);
    }

    /** Huruf tingkat tertinggi yang nilai minimalnya terpenuhi. */
    public function predicate(int|float $score): string
    {
        foreach ($this->predicates as $level) {
            if ($score >= $level['min']) {
                return $level['letter'];
            }
        }

        // Tidak tercapai bila tingkat terakhir mulai dari 0 (dijaga problem()); nilai negatif → huruf terakhir.
        $letters = $this->letters();

        return $letters === [] ? '' : $letters[count($letters) - 1];
    }

    /**
     * Ringkasan skala: skala lama → "A ≥ 90, B ≥ 80" (seperti AC-5a); selain itu
     * "A Sangat Baik ≥ 90, B Baik ≥ 75, …, E Perlu Perbaikan < 40".
     */
    public function scaleSummary(): string
    {
        $levels = $this->predicates;
        $legacy = count($levels) === 4 && ! $this->hasLabels() && $this->letters() === ['A', 'B', 'C', 'D'] && $levels[2]['min'] === $this->kkm;

        if ($legacy) {
            return "A ≥ {$levels[0]['min']}, B ≥ {$levels[1]['min']}";
        }

        $parts = [];
        $count = count($levels);

        foreach ($levels as $index => $level) {
            $name = trim($level['letter'].' '.($level['label'] ?? ''));
            $parts[] = $index === $count - 1 ? "{$name} < {$levels[$index - 1]['min']}" : "{$name} ≥ {$level['min']}";
        }

        return implode(', ', $parts);
    }

    /** Ringkasan satu baris, mis. "KKM 75 · A ≥ 90, B ≥ 80 · Nilai harian 40%, PTS 30%, PAS 30%". */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->aspects as $aspect) {
            $components = implode(', ', array_map(fn (array $component): string => "{$component['name']} {$component['weight']}%", $aspect['components']));
            $parts[] = $this->hasAspects() ? "{$aspect['name']}: {$components}" : $components;
        }

        return "KKM {$this->kkm} · {$this->scaleSummary()} · ".implode('; ', $parts);
    }

    /**
     * Pemeriksaan skala predikat (format AC-8b); null bila sah.
     */
    public static function scaleProblem(mixed $levels): ?string
    {
        if (! is_array($levels) || ! array_is_list($levels) || count($levels) < self::MIN_LEVELS || count($levels) > self::MAX_LEVELS) {
            return 'Isi '.self::MIN_LEVELS.'–'.self::MAX_LEVELS.' tingkat predikat.';
        }

        $letters = [];
        $previous = null;

        foreach ($levels as $index => $level) {
            $number = $index + 1;

            if (! is_array($level)) {
                return "Tingkat predikat ke-{$number} tidak lengkap.";
            }

            $letter = $level['letter'] ?? null;
            $label = $level['label'] ?? null;
            $min = $level['min'] ?? null;

            if (! is_string($letter) || preg_match(self::LETTER_PATTERN, $letter) !== 1) {
                return "Huruf predikat ke-{$number}: 1–2 huruf besar.";
            }

            if (isset($letters[$letter])) {
                return "Huruf predikat {$letter} dipakai lebih dari sekali.";
            }

            if ($label !== null && (! is_string($label) || mb_strlen(trim($label)) > self::LABEL_MAX_LENGTH)) {
                return "Sebutan predikat {$letter} maksimal ".self::LABEL_MAX_LENGTH.' karakter.';
            }

            if (! self::isWhole($min) || (int) $min < 0 || (int) $min > 100) {
                return "Nilai minimal predikat {$letter} harus bilangan bulat 0–100.";
            }

            if ($previous !== null && (int) $min >= $previous) {
                return "Nilai minimal predikat {$letter} harus lebih kecil dari tingkat di atasnya ({$previous}).";
            }

            $letters[$letter] = true;
            $previous = (int) $min;
        }

        return $previous === 0 ? null : 'Nilai minimal tingkat predikat terakhir harus 0.';
    }

    /**
     * Pemeriksaan format lama: batas A > batas B > KKM.
     *
     * @param  array<mixed>  $value
     */
    private static function legacyScaleProblem(array $value): ?string
    {
        foreach (['grade_a' => 'Batas predikat A', 'grade_b' => 'Batas predikat B'] as $field => $label) {
            if (! self::isWhole($value[$field] ?? null) || (int) $value[$field] < 0 || (int) $value[$field] > 100) {
                return "{$label} harus bilangan bulat 0–100.";
            }
        }

        if (! ((int) $value['grade_a'] > (int) $value['grade_b'] && (int) $value['grade_b'] > (int) $value['kkm'])) {
            return 'Batas predikat harus berurutan: batas A lebih tinggi dari batas B, dan batas B lebih tinggi dari KKM.';
        }

        return null;
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
