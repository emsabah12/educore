<?php

namespace Modules\Core\Domain\Tenancy;

enum MembershipStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
