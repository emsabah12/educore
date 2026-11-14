<?php

namespace Modules\Core\Domain\Organization;

/**
 * Status lembaga untuk data resmi (PRD-000 §4.6): Negeri / Swasta.
 */
enum OrganizationOwnership: string
{
    case Negeri = 'NEGERI';
    case Swasta = 'SWASTA';

    public function label(): string
    {
        return match ($this) {
            self::Negeri => 'Negeri',
            self::Swasta => 'Swasta',
        };
    }
}
