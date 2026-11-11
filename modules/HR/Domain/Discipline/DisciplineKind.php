<?php

namespace Modules\HR\Domain\Discipline;

/**
 * Jenis catatan disiplin (PRD-001 §18.1). SP selalu punya masa berlaku (bawaan 6 bulan, OD-HR-14).
 */
enum DisciplineKind: string
{
    case VerbalWarning = 'TEGURAN_LISAN';
    case WrittenWarning = 'TEGURAN_TERTULIS';
    case Sp1 = 'SP1';
    case Sp2 = 'SP2';
    case Sp3 = 'SP3';

    public function label(): string
    {
        return match ($this) {
            self::VerbalWarning => 'Teguran lisan',
            self::WrittenWarning => 'Teguran tertulis',
            self::Sp1 => 'SP1',
            self::Sp2 => 'SP2',
            self::Sp3 => 'SP3',
        };
    }

    public function isWarningLetter(): bool
    {
        return in_array($this, [self::Sp1, self::Sp2, self::Sp3], true);
    }
}
