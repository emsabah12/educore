<?php

declare(strict_types=1);

namespace Modules\Core\Person\Contracts;

use Modules\Core\Person\Entities\Person;

interface PersonRepositoryInterface
{
    public function findById(
        string $id,
    ): ?Person;

    public function save(
        Person $person,
    ): Person;

    public function exists(
        string $id,
    ): bool;
}
