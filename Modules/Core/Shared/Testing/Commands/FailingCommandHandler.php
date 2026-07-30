<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Testing\Commands;

use Modules\Core\Shared\Contracts\CommandHandlerInterface;
use Modules\Core\Shared\Contracts\CommandInterface;
use RuntimeException;

final class FailingCommandHandler implements CommandHandlerInterface
{
    public function handle(
        CommandInterface $command,
    ): mixed {
        throw new RuntimeException('COMMAND_HANDLER_FAILURE');
    }
}
