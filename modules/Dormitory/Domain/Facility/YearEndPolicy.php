<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Kebijakan akhir tahun ajaran per kamar (PRD-008 §9, OD-DM-12). Dijalankan di DM-5; DM-1 hanya menyimpan pilihannya.
 */
enum YearEndPolicy: string
{
    /** Penghuni tetap di kamarnya (bawaan). */
    case Keep = 'TETAP';

    /** Semua penempatan aktif diakhiri saat proses akhir tahun; riwayat tetap ada. */
    case Empty = 'KOSONGKAN';

    public function label(): string
    {
        return match ($this) {
            self::Keep => 'Tetap',
            self::Empty => 'Dikosongkan',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
