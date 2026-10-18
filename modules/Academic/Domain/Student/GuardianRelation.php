<?php

namespace Modules\Academic\Domain\Student;

/**
 * Hubungan orang tua/wali dengan siswa (PRD-002 §4.5).
 */
enum GuardianRelation: string
{
    case Father = 'AYAH';
    case Mother = 'IBU';
    case Guardian = 'WALI';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::Father => 'Ayah',
            self::Mother => 'Ibu',
            self::Guardian => 'Wali',
            self::Other => 'Lainnya',
        };
    }
}
