<?php

namespace Modules\Core\Domain\Organization;

enum AssignmentStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
