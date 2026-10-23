<?php

namespace Modules\Academic\Domain\Teaching;

/**
 * Status presensi mengajar per pertemuan (PRD-002 §20.1).
 */
enum TeachingStatus: string
{
    case Taught = 'MENGAJAR';
    case Substituted = 'DIGANTIKAN';
    case Empty = 'KOSONG';

    public function label(): string
    {
        return match ($this) {
            self::Taught => 'Mengajar',
            self::Substituted => 'Digantikan',
            self::Empty => 'Kosong',
        };
    }
}
