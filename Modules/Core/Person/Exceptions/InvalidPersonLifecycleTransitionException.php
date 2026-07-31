<?php

declare(strict_types=1);

namespace Modules\Core\Person\Exceptions;

use Modules\Core\Person\Enums\PersonStatus;
use RuntimeException;

final class InvalidPersonLifecycleTransitionException extends RuntimeException
{
    public static function from(
        PersonStatus $current,
        PersonStatus $target,
    ): self {
        return new self(
            sprintf(
                'Invalid person lifecycle transition from [%s] to [%s].',
                $current->value,
                $target->value,
            ),
        );
    }
}
