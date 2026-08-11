<?php

declare(strict_types=1);

namespace Modules\Core\Person\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Person\Contracts\PersonLifecycleEventRepositoryInterface;
use Modules\Core\Person\Contracts\PersonLifecycleServiceInterface;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Entities\PersonLifecycleEvent;
use Modules\Core\Person\Enums\PersonLifecycleEventType;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;

final readonly class PersonLifecycleService implements PersonLifecycleServiceInterface
{
    public function __construct(
        private PersonRepositoryInterface $personRepository,
        private PersonLifecycleEventRepositoryInterface $lifecycleEventRepository,
    ) {}

    public function activate(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorUserId: $actorUserId,
            reason: $reason,
            transition: static function (Person $person): void {
                $person->activate();
            },
            eventType: PersonLifecycleEventType::ACTIVATED,
        );
    }

    public function deactivate(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorUserId: $actorUserId,
            reason: $reason,
            transition: static function (Person $person): void {
                $person->deactivate();
            },
            eventType: PersonLifecycleEventType::DEACTIVATED,
        );
    }

    public function archive(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorUserId: $actorUserId,
            reason: $reason,
            transition: static function (Person $person): void {
                $person->archive();
            },
            eventType: PersonLifecycleEventType::ARCHIVED,
        );
    }

    public function markDeceased(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorUserId: $actorUserId,
            reason: $reason,
            transition: static function (Person $person): void {
                $person->markDeceased();
            },
            eventType: PersonLifecycleEventType::DECEASED,
        );
    }

    /**
     * @param callable(Person): void $transition
     */
    private function transition(
        string $personId,
        ?string $actorUserId,
        ?string $reason,
        callable $transition,
        PersonLifecycleEventType $eventType,
    ): void {
        $personId = trim($personId);

        if (! UuidV7::validate($personId)) {
            throw new RuntimeException(
                'Person identifier must be a valid UUIDv7.',
            );
        }

        if (
            $actorUserId !== null
            && ! UuidV7::validate($actorUserId)
        ) {
            throw new RuntimeException(
                'Lifecycle actor user identifier must be a valid UUIDv7.',
            );
        }

        if ($reason !== null) {
            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException(
                    'Lifecycle event reason cannot be an empty string.',
                );
            }
        }

        DB::transaction(function () use (
            $personId,
            $actorUserId,
            $reason,
            $transition,
            $eventType,
        ): void {
            $person = $this->personRepository->findById($personId);

            if ($person === null) {
                throw new RuntimeException(
                    sprintf(
                        'Person [%s] was not found.',
                        $personId,
                    ),
                );
            }

            $previousStatus = $person->status();

            $transition($person);

            if ($previousStatus === $person->status()) {
                return;
            }

            $this->personRepository->save($person);

            $event = new PersonLifecycleEvent(
                id: UuidV7::generate(),
                personId: $personId,
                type: $eventType,
                occurredAt: new DateTimeImmutable(),
                actorUserId: $actorUserId,
                reason: $reason,
            );

            $this->lifecycleEventRepository->save($event);
        });
    }
}
