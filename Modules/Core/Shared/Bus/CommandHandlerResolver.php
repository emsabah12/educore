<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Bus;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Modules\Core\Shared\Contracts\CommandInterface;
use Modules\Core\Shared\Contracts\CommandHandlerInterface;
use RuntimeException;

final class CommandHandlerResolver
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Resolve handler berdasarkan command yang diberikan.
     *
     * Convention:
     *
     * CreateUserCommand
     *        ↓
     * CreateUserCommandHandler
     */
    public function resolve(CommandInterface $command): CommandHandlerInterface
    {
        $commandClass = $command::class;

        $handlerClass = $this->resolveHandlerClass($commandClass);

        if (!class_exists($handlerClass)) {
            throw new RuntimeException(
                sprintf(
                    'Command handler [%s] untuk command [%s] tidak ditemukan.',
                    $handlerClass,
                    $commandClass,
                )
            );
        }

        $handler = $this->container->make($handlerClass);

        if (!$handler instanceof CommandHandlerInterface) {
            throw new InvalidArgumentException(
                sprintf(
                    'Command handler [%s] harus mengimplementasikan [%s].',
                    $handlerClass,
                    CommandHandlerInterface::class,
                )
            );
        }

        return $handler;
    }

    /**
     * Tentukan FQCN handler berdasarkan FQCN command.
     */
    private function resolveHandlerClass(string $commandClass): string
    {
        if (!str_ends_with($commandClass, 'Command')) {
            throw new InvalidArgumentException(
                sprintf(
                    'Command class [%s] harus memiliki suffix "Command".',
                    $commandClass,
                )
            );
        }

        return $commandClass . 'Handler';
    }
}
