<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Komposisi kamar (PRD-008 §4.3). Bukan campuran putra–putri; jenis kelamin selalu mengikuti gedung.
 */
enum RoomComposition: string
{
    /** Hanya penghuni reguler; wali kamar boleh mengawasi tetapi tidak tinggal di kamar ini. */
    case ResidentsOnly = 'KHUSUS_SANTRI';

    /** Penghuni reguler + pengawas yang tinggal di kamar; wajib punya slot pengawas ≥ 1. */
    case WithSupervisor = 'SANTRI_PENGAWAS';

    public function label(): string
    {
        return match ($this) {
            self::ResidentsOnly => 'Khusus santri',
            self::WithSupervisor => 'Santri + pengawas',
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
