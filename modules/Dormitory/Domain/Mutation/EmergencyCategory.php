<?php

namespace Modules\Dormitory\Domain\Mutation;

/** Kategori mutasi darurat (PRD-008 §8.2). */
enum EmergencyCategory: string
{
    case Health = 'KESEHATAN';
    case Safety = 'KEAMANAN';
    case Conflict = 'KONFLIK';
    case Facility = 'FASILITAS';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::Health => 'Kesehatan',
            self::Safety => 'Keamanan/keselamatan',
            self::Conflict => 'Konflik',
            self::Facility => 'Fasilitas rusak',
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
