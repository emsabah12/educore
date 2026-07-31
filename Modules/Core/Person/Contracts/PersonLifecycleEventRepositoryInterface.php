<?php

declare(strict_types=1);

namespace Modules\Core\Person\Contracts;

use Modules\Core\Person\Entities\PersonLifecycleEvent;

interface PersonLifecycleEventRepositoryInterface
{
    public function findById(
        string $id,
    ): ?PersonLifecycleEvent;

    /**
     * @return list<PersonLifecycleEvent>
     */
    public function findByPersonId(
        string $personId,
    ): array;

    public function save(
        PersonLifecycleEvent $event,
    ): PersonLifecycleEvent;
}
