<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Exceptions;

use RuntimeException;

final class CapabilityProjectionContextException extends RuntimeException
{
    public static function unresolvedAuthenticatedUser(): self
    {
        return new self(
            'Authenticated user context cannot be resolved for capability projection.',
        );
    }

    public static function missingOrganizationalContext(): self
    {
        return new self(
            'Organizational context is required for workspace capability projection.',
        );
    }

    public static function unresolvedOrganizationalContext(): self
    {
        return new self(
            'Organizational context cannot be resolved for workspace capability projection.',
        );
    }

    public static function organizationalContextMismatch(): self
    {
        return new self(
            'Organizational context does not match the authenticated authorization context.',
        );
    }
}
