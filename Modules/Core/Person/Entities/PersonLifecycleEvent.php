<?php

declare(strict_types=1);

namespace Modules\Core\Person\Entities;

use DateTimeImmutable;
use Modules\Core\Person\Enums\PersonLifecycleEventType;
use RuntimeException;

final readonly class PersonLifecycleEvent
{
    public function __construct(
        private string $id,
        private string $personId,
        private PersonLifecycleEventType $type,
        private DateTimeImmutable $occurredAt,
        private ?string $actorId = null,
        private ?string $reason = null,
    ) {
        if (trim($id) === '') {
            throw new RuntimeException(
                'Person lifecycle event identifier cannot be empty.',
            );
        }

        if (trim($personId) === '') {
            throw new RuntimeException(
                'Person identifier cannot be empty.',
            );
        }

        if ($reason !== null && trim($reason) === '') {
            throw new RuntimeException(
                'Lifecycle event reason cannot be an empty string.',
            );
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function personId(): string
    {
        return $this->personId;
    }

    public function type(): PersonLifecycleEventType
    {
        return $this->type;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
