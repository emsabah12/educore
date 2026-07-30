<?php

declare(strict_types=1);

namespace Modules\Core\Shared\UnitOfWork;

use Modules\Core\Shared\Contracts\RepositoryEventInterface;
use Modules\Core\Shared\Repositories\Contracts\TransactionManagerInterface;
use Modules\Core\Shared\Contracts\UnitOfWorkInterface;

final class UnitOfWork implements UnitOfWorkInterface
{
    /**
     * @var array<RepositoryEventInterface>
     */
    private array $events = [];

    public function __construct(
        private readonly TransactionManagerInterface $transactionManager,
    ) {}

    /**
     * @template TReturn
     *
     * @param callable():TReturn $callback
     *
     * @return TReturn
     */
    public function execute(callable $callback): mixed
    {
        return $this->transactionManager->transaction(function () use ($callback) {
            return $callback();
        });
    }

    public function collect(
        RepositoryEventInterface $event,
    ): void {
        $this->events[] = $event;
    }

    /**
     * @return array<RepositoryEventInterface>
     */
    public function pullEvents(): array
    {
        $events = $this->events;

        $this->events = [];

        return $events;
    }

    public function clearEvents(): void
    {
        $this->events = [];
    }
}
