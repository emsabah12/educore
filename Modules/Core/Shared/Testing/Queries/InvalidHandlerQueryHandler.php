<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Testing\Queries;

final class InvalidHandlerQueryHandler
{
    public function handle(
        InvalidHandlerQuery $query,
    ): string {
        return 'INVALID_HANDLER_EXECUTED:' . $query->message;
    }
}
