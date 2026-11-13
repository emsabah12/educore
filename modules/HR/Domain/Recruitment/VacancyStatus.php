<?php

namespace Modules\HR\Domain\Recruitment;

/**
 * Status lowongan (PRD-001 §20.1): Draf → Dibuka → Ditutup (ditutup boleh dibuka lagi).
 */
enum VacancyStatus: string
{
    case Draft = 'DRAF';
    case Open = 'DIBUKA';
    case Closed = 'DITUTUP';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Open => 'Dibuka',
            self::Closed => 'Ditutup',
        };
    }
}
