<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Contracts;

/**
 * Contract untuk seluruh Query Handler.
 *
 * @template TQuery of QueryInterface
 * @template TResult
 */
interface QueryHandlerInterface
{
    /**
     * Menangani sebuah query.
     *
     * @param TQuery $query
     * @return TResult
     */
    public function handle(
        QueryInterface $query,
    ): mixed;
}
