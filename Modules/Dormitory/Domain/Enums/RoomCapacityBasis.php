<?php

declare(strict_types=1);

namespace Modules\Dormitory\Domain\Enums;

enum RoomCapacityBasis: string
{
    case BED = 'BED';
    case LOCKER = 'LOCKER';
    case BED_AND_LOCKER = 'BED_AND_LOCKER';
}
