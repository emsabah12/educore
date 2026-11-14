<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Jenis sumber daya kamar yang menentukan kapasitas (PRD-008 §4.4). Nilainya juga dipakai di alamat halaman
 * (`/asrama/bed/{id}`, `/asrama/loker/{id}`).
 */
enum ResourceKind: string
{
    case Bed = 'bed';
    case Locker = 'loker';

    public function label(): string
    {
        return match ($this) {
            self::Bed => 'Bed',
            self::Locker => 'Loker',
        };
    }

    /**
     * @return class-string<Bed|Locker>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Bed => Bed::class,
            self::Locker => Locker::class,
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Bed => 'dm_beds',
            self::Locker => 'dm_lockers',
        };
    }
}
