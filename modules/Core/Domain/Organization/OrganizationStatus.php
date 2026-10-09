<?php

namespace Modules\Core\Domain\Organization;

enum OrganizationStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
