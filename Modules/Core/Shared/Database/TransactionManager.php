<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Database;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Shared\Repositories\Contracts\TransactionManagerInterface;

final class TransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction($callback);
    }

    public function beginTransaction(): void
    {
        $this->database->beginTransaction();
    }

    public function commit(): void
    {
        $this->database->commit();
    }

    public function rollBack(): void
    {
        $this->database->rollBack();
    }
}
