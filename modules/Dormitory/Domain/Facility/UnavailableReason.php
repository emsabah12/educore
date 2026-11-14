<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Alasan bed/loker tidak tersedia (PRD-008 §4.5).
 */
enum UnavailableReason: string
{
    case Broken = 'RUSAK';
    case Repair = 'PERBAIKAN';
    case Closed = 'DITUTUP';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::Broken => 'Rusak',
            self::Repair => 'Perbaikan',
            self::Closed => 'Ditutup sementara',
            self::Other => 'Lainnya',
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
