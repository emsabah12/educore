<?php

declare(strict_types=1);

namespace Modules\Core\Person\DTO;

use DateTimeImmutable;
use Modules\Core\Person\Enums\PersonLegalSex;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\ValueObjects\PersonName;

final readonly class PersonData
{
    public function __construct(
        public string $id,
        public PersonName $name,
        public PersonStatus $status = PersonStatus::ACTIVE,
        public ?string $givenName = null,
        public ?string $middleName = null,
        public ?string $familyName = null,
        public ?DateTimeImmutable $birthDate = null,
        public ?string $birthPlaceName = null,
        public ?string $birthCountryCode = null,
        public ?PersonLegalSex $legalSex = null,
        public ?string $civilStatus = null,
    ) {}
}
