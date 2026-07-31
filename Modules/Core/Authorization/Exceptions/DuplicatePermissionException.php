<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Exceptions;

final class DuplicatePermissionException extends AuthorizationRegistryException
{
    public function __construct(string $permission)
    {
        parent::__construct(
            sprintf(
                'Duplicate authorization permission [%s] detected.',
                $permission,
            ),
        );
    }
}
