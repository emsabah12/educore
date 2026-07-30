<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Testing\Commands;

use Modules\Core\Shared\Contracts\CommandInterface;

final readonly class TransactionalCommand implements CommandInterface
{
    public function __construct(
        public string $marker,
    ) {}
}
