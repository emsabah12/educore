<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Testing\Queries;

use Modules\Core\Shared\Contracts\QueryInterface;

final readonly class DummyQuery implements QueryInterface
{
    public function __construct(
        public string $message,
    ) {}
}
