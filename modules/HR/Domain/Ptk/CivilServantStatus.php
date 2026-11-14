<?php

namespace Modules\HR\Domain\Ptk;

/**
 * Status PNS untuk Simpatika (PRD-001 §21.1); terpisah dari status kepegawaian yayasan (GTY/GTT/PTY/PTT).
 */
enum CivilServantStatus: string
{
    case Pns = 'PNS';
    case NonPns = 'NON_PNS';

    public function label(): string
    {
        return match ($this) {
            self::Pns => 'PNS',
            self::NonPns => 'Non-PNS',
        };
    }
}
