<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Testing\Queries;

use InvalidArgumentException;
use Modules\Core\Shared\Contracts\QueryHandlerInterface;
use Modules\Core\Shared\Contracts\QueryInterface;

final class DummyQueryHandler implements QueryHandlerInterface
{
    public function handle(
        QueryInterface $query,
    ): mixed {
        if (!$query instanceof DummyQuery) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected [%s], received [%s].',
                    DummyQuery::class,
                    $query::class,
                )
            );
        }

        return 'HANDLER_EXECUTED:' . $query->message;
    }
}
