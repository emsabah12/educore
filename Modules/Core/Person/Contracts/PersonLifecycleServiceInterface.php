<?php

declare(strict_types=1);

namespace Modules\Core\Person\Contracts;

interface PersonLifecycleServiceInterface
{
    public function activate(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void;

    public function deactivate(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void;

    public function archive(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void;

    public function markDeceased(
        string $personId,
        ?string $actorId = null,
        ?string $reason = null,
    ): void;
}
