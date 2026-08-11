<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

final class InvalidInitialTenantAdminException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self(
            'The initial admin must be an active User linked to an active Person.',
        );
    }
}
