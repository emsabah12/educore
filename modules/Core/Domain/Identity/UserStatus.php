<?php

namespace Modules\Core\Domain\Identity;

enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
