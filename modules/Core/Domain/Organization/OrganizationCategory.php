<?php

namespace Modules\Core\Domain\Organization;

enum OrganizationCategory: string
{
    case Formal = 'FORMAL';
    case Nonformal = 'NONFORMAL';
    case Pesantren = 'PESANTREN';

    /**
     * Nama tampilan dalam Bahasa Indonesia.
     */
    public function label(): string
    {
        return match ($this) {
            self::Formal => 'Formal',
            self::Nonformal => 'Nonformal',
            self::Pesantren => 'Pesantren',
        };
    }
}
