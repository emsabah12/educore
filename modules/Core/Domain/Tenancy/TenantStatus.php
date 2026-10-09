<?php

namespace Modules\Core\Domain\Tenancy;

enum TenantStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
}
