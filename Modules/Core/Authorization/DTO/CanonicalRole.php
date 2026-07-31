<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\DTO;

final readonly class CanonicalRole
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {}
}
