<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

final class TenantContextNotResolvedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Tenant context has not been resolved for the current application lifecycle.'
        );
    }
}
