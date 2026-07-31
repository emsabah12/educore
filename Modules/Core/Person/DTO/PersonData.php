<?php

declare(strict_types=1);

namespace Modules\Core\Person\DTO;

use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\ValueObjects\PersonName;

final readonly class PersonData
{
    public function __construct(
        public string $id,
        public PersonName $name,
        public PersonStatus $status = PersonStatus::ACTIVE,
    ) {}
}
