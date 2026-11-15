<?php

namespace Modules\Dormitory\Domain\Placement;

/**
 * Status penempatan (PRD-008 §5.2). Direncanakan & Aktif memakai/memesan bed-loker dan dihitung dalam hunian;
 * Selesai & Dibatalkan tinggal riwayat.
 */
enum PlacementStatus: string
{
    case Planned = 'DIRENCANAKAN';
    case Active = 'AKTIF';
    case Ended = 'SELESAI';
    case Cancelled = 'DIBATALKAN';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Direncanakan',
            self::Active => 'Aktif',
            self::Ended => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Status yang memegang tempat (dihitung dalam kapasitas & memesan bed/loker).
     *
     * @return list<string>
     */
    public static function holdingValues(): array
    {
        return [self::Planned->value, self::Active->value];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
