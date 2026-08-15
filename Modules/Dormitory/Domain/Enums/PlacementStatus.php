<?php

declare(strict_types=1);

namespace Modules\Dormitory\Domain\Enums;

enum PlacementStatus: string
{
    case PLANNED = 'PLANNED';
    case ACTIVE = 'ACTIVE';
    case ENDED = 'ENDED';
    case CANCELLED = 'CANCELLED';
}
