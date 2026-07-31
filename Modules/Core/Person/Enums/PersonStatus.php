<?php

declare(strict_types=1);

namespace Modules\Core\Person\Enums;

enum PersonStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case ARCHIVED = 'ARCHIVED';
    case DECEASED = 'DECEASED';
}
