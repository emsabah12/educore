<?php

declare(strict_types=1);

namespace Modules\Core\Person\Repositories;

use DateTimeImmutable;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Enums\PersonLegalSex;
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
            $record = $this->model->newInstance();
            $record->id = $person->id();
        }

        $record->name = (string) $person->name();
        $record->given_name = $person->givenName();
        $record->middle_name = $person->middleName();
        $record->family_name = $person->familyName();
        $record->birth_date = $person->birthDate();
        $record->birth_place_name = $person->birthPlaceName();
        $record->birth_country_code = $person->birthCountryCode();
        $record->legal_sex = $person->legalSex()?->value;
        $record->civil_status = $person->civilStatus();
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
        $id = trim((string) $record->id);

        if ($id === '') {
            throw new RuntimeException(
                'Cannot reconstruct Person: database identifier is empty.',
            );
        }

        $name = trim((string) $record->name);

        if ($name === '') {
            throw new RuntimeException(
                'Cannot reconstruct Person: database name is empty.',
            );
        }

        $statusValue = trim((string) $record->status);

        if ($statusValue === '') {
            throw new RuntimeException(
                'Cannot reconstruct Person: database status is empty.',
            );
        }

        $status = PersonStatus::tryFrom($statusValue);

        if ($status === null) {
            throw new RuntimeException(
                sprintf(
                    'Cannot reconstruct Person: unknown status [%s].',
                    $statusValue,
                ),
            );
        }

        $legalSexValue = $record->legal_sex !== null
            ? trim((string) $record->legal_sex)
            : null;

        $legalSex = null;

        if ($legalSexValue !== null && $legalSexValue !== '') {
            $legalSex = PersonLegalSex::tryFrom($legalSexValue);

            if ($legalSex === null) {
                throw new RuntimeException(
                    sprintf(
                        'Cannot reconstruct Person: unknown legal sex [%s].',
                        $legalSexValue,
                    ),
                );
            }
        }

        $birthDate = $record->birth_date !== null
            ? DateTimeImmutable::createFromInterface($record->birth_date)
            : null;

        return new Person(
            id: $id,
            name: new PersonName($name),
            status: $status,
            givenName: self::nullableTrimmedString($record->given_name),
            middleName: self::nullableTrimmedString($record->middle_name),
            familyName: self::nullableTrimmedString($record->family_name),
            birthDate: $birthDate,
            birthPlaceName: self::nullableTrimmedString($record->birth_place_name),
            birthCountryCode: self::nullableTrimmedString($record->birth_country_code),
            legalSex: $legalSex,
            civilStatus: self::nullableTrimmedString($record->civil_status),
        );
    }

    private static function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
