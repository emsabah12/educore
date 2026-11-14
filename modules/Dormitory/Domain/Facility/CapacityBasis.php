<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Dasar kapasitas kamar (PRD-008 §4.4, OWNER Agustus 2026): ada kamar yang dihitung dari bed, dari pintu
 * lemari/loker, atau dari bed + loker sekaligus.
 */
enum CapacityBasis: string
{
    case Bed = 'BED';
    case Locker = 'LOKER';
    case BedAndLocker = 'BED_LOKER';

    public function label(): string
    {
        return match ($this) {
            self::Bed => 'Bed',
            self::Locker => 'Loker',
            self::BedAndLocker => 'Bed + loker',
        };
    }

    /**
     * Kapasitas efektif dari jumlah bed & loker yang tersedia. Bed + loker memakai nilai terkecil, bukan
     * dijumlahkan: 20 bed + 16 loker = 16 penghuni (setiap penghuni butuh keduanya).
     */
    public function effectiveCapacity(int $availableBeds, int $availableLockers): int
    {
        return match ($this) {
            self::Bed => $availableBeds,
            self::Locker => $availableLockers,
            self::BedAndLocker => min($availableBeds, $availableLockers),
        };
    }

    public function requiresBed(): bool
    {
        return $this !== self::Locker;
    }

    public function requiresLocker(): bool
    {
        return $this !== self::Bed;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
