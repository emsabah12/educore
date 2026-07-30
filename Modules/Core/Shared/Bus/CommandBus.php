<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Bus;

use Modules\Core\Shared\Contracts\CommandBusInterface;
use Modules\Core\Shared\Contracts\CommandHandlerInterface;
use Modules\Core\Shared\Contracts\CommandInterface;

final readonly class CommandBus implements CommandBusInterface
{
    public function __construct(
        private CommandHandlerResolver $resolver,
    ) {}

    /**
     * Dispatch sebuah command kepada handler yang sesuai.
     *
     * CommandBus hanya bertanggung jawab sebagai dispatcher.
     * Resolusi handler sepenuhnya didelegasikan kepada
     * CommandHandlerResolver.
     */
    public function dispatch(
        CommandInterface $command,
    ): mixed {
        $handler = $this->resolver->resolve($command);

        if (! $handler instanceof CommandHandlerInterface) {
            throw new \RuntimeException(
                sprintf(
                    'Resolved command handler [%s] must implement [%s].',
                    $handler::class,
                    CommandHandlerInterface::class,
                )
            );
        }

        return $handler->handle($command);
    }
}
