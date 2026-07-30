<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Contracts;

use DateTimeImmutable;

interface RepositoryEventInterface
{
    /**
     * Event identifier.
     */
    public function eventId(): string;

    /**
     * Aggregate/model identifier.
     */
    public function aggregateId(): string;

    /**
     * Model class name.
     */
    public function aggregateType(): string;

    /**
     * Event name.
     */
    public function eventName(): string;

    /**
     * UTC occurrence timestamp.
     */
    public function occurredAt(): DateTimeImmutable;

    public function payload(): array;
}
