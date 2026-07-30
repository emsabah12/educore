<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Contracts;

interface QueryBusInterface
{
    /**
     * Dispatch sebuah query ke handler yang sesuai.
     */
    public function dispatch(
        QueryInterface $query,
    ): mixed;
}
