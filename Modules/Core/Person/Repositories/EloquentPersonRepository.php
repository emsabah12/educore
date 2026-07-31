<?php

declare(strict_types=1);

namespace Modules\Core\Person\Repositories;

use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Person\ValueObjects\PersonName;
use RuntimeException;

final class EloquentPersonRepository implements PersonRepositoryInterface
{
    public function __construct(
        private readonly PersonModel $model,
    ) {}

    public function findById(string $id): ?Person
    {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Person identifier cannot be empty.',
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

    public function save(Person $person): Person
    {
        $record = $this->model
            ->newQuery()
            ->find($person->id());

        if ($record === null) {
            $record = $this->model
                ->newInstance();

            $record->id = $person->id();
        }

        $record->name = (string) $person->name();
        $record->status = $person->status()->value;

        $record->save();

        return $this->toDomain($record);
    }

    public function exists(string $id): bool
    {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Person identifier cannot be empty.',
            );
        }

        return $this->model
            ->newQuery()
            ->whereKey($id)
            ->exists();
    }

    private function toDomain(PersonModel $record): Person
    {
        if ($record->id === null || trim((string) $record->id) === '') {
            throw new RuntimeException(
                'Cannot reconstruct Person: database identifier is empty.',
            );
        }

        if ($record->name === null || trim((string) $record->name) === '') {
            throw new RuntimeException(
                'Cannot reconstruct Person: database name is empty.',
            );
        }

        if ($record->status === null || trim((string) $record->status) === '') {
            throw new RuntimeException(
                'Cannot reconstruct Person: database status is empty.',
            );
        }

        $status = PersonStatus::tryFrom(
            (string) $record->status,
        );

        if ($status === null) {
            throw new RuntimeException(
                sprintf(
                    'Cannot reconstruct Person: unknown status [%s].',
                    $record->status,
                ),
            );
        }

        return new Person(
            id: (string) $record->id,
            name: new PersonName(
                (string) $record->name,
            ),
            status: $status,
        );
    }
}
