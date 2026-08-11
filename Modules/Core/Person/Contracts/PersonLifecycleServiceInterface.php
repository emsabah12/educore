<?php

declare(strict_types=1);

namespace Modules\Core\Person\Contracts;

interface PersonLifecycleServiceInterface
{
    public function activate(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void;

    public function deactivate(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void;

    public function archive(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void;

    public function markDeceased(
        string $personId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void;
}
