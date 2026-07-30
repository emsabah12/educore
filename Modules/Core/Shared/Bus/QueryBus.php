<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Bus;

use Modules\Core\Shared\Contracts\QueryBusInterface;
use Modules\Core\Shared\Contracts\QueryInterface;

final readonly class QueryBus implements QueryBusInterface
{
    public function __construct(
        private QueryHandlerResolver $resolver,
    ) {}

    /**
     * Dispatch query ke handler yang sesuai.
     */
    public function dispatch(
        QueryInterface $query,
    ): mixed {
        $handler = $this->resolver->resolve($query);

        return $handler->handle($query);
    }
}
