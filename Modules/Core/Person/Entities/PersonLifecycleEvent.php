<?php

declare(strict_types=1);

namespace Modules\Core\Person\Entities;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Core\Person\Enums\PersonLifecycleEventType;
use Modules\Core\Support\Uuid\UuidV7;

final readonly class PersonLifecycleEvent
{
    private ?string $reason;

    public function __construct(
        private string $id,
        private string $personId,
        private PersonLifecycleEventType $type,
        private DateTimeImmutable $occurredAt,
        private ?string $actorUserId = null,
        ?string $reason = null,
    ) {
        if (! UuidV7::validate($id)) {
            throw new InvalidArgumentException(
                'Person lifecycle event identifier must be a valid UUIDv7.',
            );
        }

        if (! UuidV7::validate($personId)) {
            throw new InvalidArgumentException(
                'Person lifecycle event person identifier must be a valid UUIDv7.',
            );
        }

        if (
            $actorUserId !== null
            && ! UuidV7::validate($actorUserId)
        ) {
            throw new InvalidArgumentException(
                'Person lifecycle event actor user identifier must be a valid UUIDv7.',
            );
        }

        if ($reason !== null) {
            $reason = trim($reason);

            if ($reason === '') {
                throw new InvalidArgumentException(
                    'Lifecycle event reason cannot be an empty string.',
                );
            }
        }

        $this->reason = $reason;
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

    public function actorUserId(): ?string
    {
        return $this->actorUserId;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
