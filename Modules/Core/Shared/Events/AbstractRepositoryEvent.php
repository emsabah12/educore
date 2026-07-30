<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Events;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Core\Shared\Contracts\RepositoryEventInterface;

abstract readonly class AbstractRepositoryEvent implements RepositoryEventInterface
{
    private string $eventId;

    private DateTimeImmutable $occurredAt;

    public function __construct(
        private string $aggregateId,
    ) {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateType(): string
    {
        return static::aggregateClass();
    }

    public function eventName(): string
    {
        return class_basename(static::class);
    }

    abstract protected static function aggregateClass(): string;
}
