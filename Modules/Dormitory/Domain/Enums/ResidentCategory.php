<?php

declare(strict_types=1);

namespace Modules\Dormitory\Domain\Enums;

enum ResidentCategory: string
{
    case REGULAR_RESIDENT = 'REGULAR_RESIDENT';
    case SUPERVISOR_RESIDENT = 'SUPERVISOR_RESIDENT';
}
