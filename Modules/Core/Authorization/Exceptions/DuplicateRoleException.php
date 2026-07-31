<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Exceptions;

final class DuplicateRoleException extends AuthorizationRegistryException
{
    public function __construct(string $role)
    {
        parent::__construct(
            sprintf(
                'Duplicate authorization role [%s] detected.',
                $role,
            ),
        );
    }
}
