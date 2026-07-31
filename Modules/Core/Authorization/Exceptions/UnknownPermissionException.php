<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Exceptions;

final class UnknownPermissionException extends AuthorizationRegistryException
{
    public function __construct(string $permission)
    {
        parent::__construct(
            sprintf(
                'Authorization permission [%s] has not been registered.',
                $permission,
            ),
        );
    }
}
