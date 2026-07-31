<?php

declare(strict_types=1);

namespace Modules\Core\Person\Repositories;

use DateTimeImmutable;
use Modules\Core\Person\Contracts\PersonLifecycleEventRepositoryInterface;
use Modules\Core\Person\Entities\PersonLifecycleEvent;
use Modules\Core\Person\Enums\PersonLifecycleEventType;
use Modules\Core\Person\Models\PersonLifecycleEventModel;
use RuntimeException;

final class EloquentPersonLifecycleEventRepository implements PersonLifecycleEventRepositoryInterface
{
    public function __construct(
        private readonly PersonLifecycleEventModel $model,
    ) {}

    public function findById(string $id): ?PersonLifecycleEvent
    {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Person lifecycle event identifier cannot be empty.',
            );
        }

        $record = $this->model
            ->newQuery()
            ->find($id);

        if ($record === null) {
            return null;
        }

        return $this->toDomain($record);
    }

    public function findByPersonId(string $personId): array
    {
        $personId = trim($personId);

        if ($personId === '') {
            throw new RuntimeException(
                'Person identifier cannot be empty.',
            );
        }

        return $this->model
            ->newQuery()
            ->where('person_id', $personId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn(PersonLifecycleEventModel $record): PersonLifecycleEvent
                => $this->toDomain($record),
            )
            ->all();
    }

    public function save(
        PersonLifecycleEvent $event,
    ): PersonLifecycleEvent {
        $record = $this->model
            ->newQuery()
            ->find($event->id());

        if ($record !== null) {
            throw new RuntimeException(
                sprintf(
                    'Person lifecycle event [%s] already exists and cannot be modified.',
                    $event->id(),
                ),
            );
        }

        $record = $this->model->newInstance();

        $record->id = $event->id();
        $record->person_id = $event->personId();
        $record->type = $event->type()->value;
        $record->occurred_at = $event->occurredAt();

        $record->actor_id = $event->actorId();
        $record->reason = $event->reason();

        $record->save();

        return $this->toDomain($record);
    }

    private function toDomain(
        PersonLifecycleEventModel $record,
    ): PersonLifecycleEvent {
        $id = trim((string) $record->id);

        if ($id === '') {
            throw new RuntimeException(
                'Cannot reconstruct lifecycle event: identifier is empty.',
            );
        }

        $personId = trim((string) $record->person_id);

        if ($personId === '') {
            throw new RuntimeException(
                'Cannot reconstruct lifecycle event: person identifier is empty.',
            );
        }

        $typeValue = trim((string) $record->type);

        if ($typeValue === '') {
            throw new RuntimeException(
                'Cannot reconstruct lifecycle event: event type is empty.',
            );
        }

        $type = PersonLifecycleEventType::tryFrom($typeValue);

        if ($type === null) {
            throw new RuntimeException(
                sprintf(
                    'Cannot reconstruct lifecycle event: unknown event type [%s].',
                    $typeValue,
                ),
            );
        }

        $occurredAt = $record->occurred_at;

        if ($occurredAt === null) {
            throw new RuntimeException(
                'Cannot reconstruct lifecycle event: occurred_at is empty.',
            );
        }

        $occurredAt = new DateTimeImmutable(
            $occurredAt->format('Y-m-d H:i:s.uP'),
        );

        $actorId = $record->actor_id !== null
            ? trim((string) $record->actor_id)
            : null;

        if ($actorId === '') {
            $actorId = null;
        }

        $reason = $record->reason !== null
            ? trim((string) $record->reason)
            : null;

        if ($reason === '') {
            $reason = null;
        }

        return new PersonLifecycleEvent(
            id: $id,
            personId: $personId,
            type: $type,
            occurredAt: $occurredAt,
            actorId: $actorId,
            reason: $reason,
        );
    }
}
