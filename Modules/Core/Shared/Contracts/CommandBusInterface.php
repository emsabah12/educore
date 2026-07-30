<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Contracts;

interface CommandBusInterface
{
    /**
     * Dispatch command ke handler yang sesuai.
     */
    public function dispatch(
        CommandInterface $command,
    ): mixed;
}
