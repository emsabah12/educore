<?php

namespace Modules\HR\Domain\Performance;

/**
 * Kelompok penilaian kinerja (PRD-001 §19.2): komponen & bobot guru dan staf terpisah.
 */
enum PerformanceGroup: string
{
    case Teacher = 'GURU';
    case Staff = 'STAF';

    public function label(): string
    {
        return match ($this) {
            self::Teacher => 'Guru',
            self::Staff => 'Staf',
        };
    }

    /** Segmen alamat (`/pegawai/kinerja/komponen/guru`). */
    public function slug(): string
    {
        return strtolower($this->value);
    }

    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom(strtoupper($slug));
    }

    /**
     * Isi awal komponen (PRD-001 §19.2), dipakai selama yayasan belum menyimpan komponennya sendiri.
     *
     * @return list<array{name: string, weight: int}>
     */
    public function defaults(): array
    {
        $names = match ($this) {
            self::Teacher => ['Pedagogik', 'Kepribadian', 'Sosial', 'Profesional'],
            self::Staff => ['Kedisiplinan', 'Kualitas kerja', 'Kerja sama', 'Inisiatif'],
        };

        return array_map(fn (string $name): array => ['name' => $name, 'weight' => 25], $names);
    }
}
