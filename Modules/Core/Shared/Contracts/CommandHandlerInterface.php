<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Contracts;

interface CommandHandlerInterface
{
    /**
     * Menangani sebuah command.
     */
    public function handle(
        CommandInterface $command,
    ): mixed;
}
