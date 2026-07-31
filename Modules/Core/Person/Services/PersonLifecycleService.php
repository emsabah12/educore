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
use Modules\Core\Person\Enums\PersonStatus;
use RuntimeException;

final readonly class PersonLifecycleService implements PersonLifecycleServiceInterface
{
    public function __construct(
        private PersonRepositoryInterface $personRepository,
        private PersonLifecycleEventRepositoryInterface $lifecycleEventRepository,
    ) {}

    public function activate(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorId: $actorId,
            reason: $reason,
            transition: static function (Person $person): void {
                $person->activate();
            },
            eventType: PersonLifecycleEventType::ACTIVATED,
        );
    }

    public function deactivate(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorId: $actorId,
            reason: $reason,
            transition: static function (Person $person): void {
                $person->deactivate();
            },
            eventType: PersonLifecycleEventType::DEACTIVATED,
        );
    }

    public function archive(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorId: $actorId,
            reason: $reason,
            transition: static function (Person $person): void {
                $person->archive();
            },
            eventType: PersonLifecycleEventType::ARCHIVED,
        );
    }

    public function markDeceased(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void {
        $this->transition(
            personId: $personId,
            actorId: $actorId,
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
        ?string $actorId,
        ?string $reason,
        callable $transition,
        PersonLifecycleEventType $eventType,
    ): void {
        $personId = trim($personId);

        if ($personId === '') {
            throw new RuntimeException(
                'Person identifier cannot be empty.',
            );
        }

        if ($actorId !== null) {
            $actorId = trim($actorId);

            if ($actorId === '') {
                throw new RuntimeException(
                    'Lifecycle actor identifier cannot be empty.',
                );
            }
        }

        DB::transaction(function () use (
            $personId,
            $actorId,
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

            $currentStatus = $person->status();

            /*
             * Same-state commands are intentionally idempotent.
             *
             * Example:
             *
             * ACTIVE -> activate() -> ACTIVE
             *
             * No persistence and no lifecycle event are required
             * because the Person lifecycle did not actually change.
             */
            if ($previousStatus === $currentStatus) {
                return;
            }

            if ($reason !== null) {
                $reason = trim($reason);

                if ($reason === '') {
                    throw new RuntimeException(
                        'Lifecycle event reason cannot be an empty string.',
                    );
                }
            }

            $this->personRepository->save($person);

            $event = new PersonLifecycleEvent(
                id: $this->generateEventId(),
                personId: $personId,
                type: $eventType,
                occurredAt: new DateTimeImmutable(),
                actorId: $actorId,
                reason: $reason,
            );

            $this->lifecycleEventRepository->save($event);
        });
    }

    private function generateEventId(): string
    {
        return (string) str()->uuid();
    }
}
