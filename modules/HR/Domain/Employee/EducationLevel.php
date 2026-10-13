<?php

namespace Modules\HR\Domain\Employee;

/**
 * Pendidikan terakhir (PRD-001 §4.3).
 */
enum EducationLevel: string
{
    case Sd = 'SD';
    case Smp = 'SMP';
    case Sma = 'SMA';
    case D1 = 'D1';
    case D2 = 'D2';
    case D3 = 'D3';
    case D4S1 = 'D4_S1';
    case S2 = 'S2';
    case S3 = 'S3';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::Sd => 'SD/MI',
            self::Smp => 'SMP/MTs',
            self::Sma => 'SMA/SMK/MA/sederajat',
            self::D1 => 'D1',
            self::D2 => 'D2',
            self::D3 => 'D3',
            self::D4S1 => 'D4/S1',
            self::S2 => 'S2',
            self::S3 => 'S3',
            self::Other => 'Lainnya',
        };
    }
}
