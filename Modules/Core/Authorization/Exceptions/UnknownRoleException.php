<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Exceptions;

final class UnknownRoleException extends AuthorizationRegistryException
{
    public function __construct(string $role)
    {
        parent::__construct(
            sprintf(
                'Authorization role [%s] has not been registered.',
                $role,
            ),
        );
    }
}
