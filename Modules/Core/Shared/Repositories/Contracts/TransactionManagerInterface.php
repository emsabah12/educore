<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Repositories\Contracts;

interface TransactionManagerInterface
{
    /**
     * Execute callback inside database transaction.
     *
     * @template TReturn
     *
     * @param callable():TReturn $callback
     *
     * @return TReturn
     */
    public function transaction(callable $callback): mixed;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
