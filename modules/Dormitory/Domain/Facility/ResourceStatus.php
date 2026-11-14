<?php

namespace Modules\Dormitory\Domain\Facility;

/**
 * Status bed/loker (PRD-008 §4.5). "Terisi" sengaja tidak disimpan: dihitung dari penempatan (DM-2) agar tidak
 * ada dua sumber kebenaran [LAMA].
 */
enum ResourceStatus: string
{
    case Available = 'TERSEDIA';
    case Unavailable = 'TIDAK_TERSEDIA';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Unavailable => 'Tidak tersedia',
        };
    }
}
