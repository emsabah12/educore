<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\DTO;

final readonly class CanonicalGrant
{
    public function __construct(
        public string $role,
        public string $permission,
    ) {}
}
