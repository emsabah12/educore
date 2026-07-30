<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Testing\Commands;

use Modules\Core\Shared\Contracts\CommandHandlerInterface;
use Modules\Core\Shared\Contracts\CommandInterface;

final class DummyCommandHandler implements CommandHandlerInterface
{
    public function handle(
        CommandInterface $command,
    ): mixed {
        if (! $command instanceof DummyCommand) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Expected [%s], received [%s].',
                    DummyCommand::class,
                    $command::class,
                )
            );
        }

        return 'HANDLER_EXECUTED:' . $command->message;
    }
}
