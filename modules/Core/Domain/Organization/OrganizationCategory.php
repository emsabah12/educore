<?php

namespace Modules\Core\Domain\Organization;

enum OrganizationCategory: string
{
    case Formal = 'FORMAL';
    case Nonformal = 'NONFORMAL';
    case Pesantren = 'PESANTREN';
}
