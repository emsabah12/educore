<?php

namespace Modules\Academic\Domain\Student;

/**
 * Cara siswa masuk ke lembaga (PRD-002 §4.2).
 */
enum EntryType: string
{
    case New = 'BARU';
    case Transfer = 'PINDAHAN';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Siswa baru',
            self::Transfer => 'Pindahan',
        };
    }
}
