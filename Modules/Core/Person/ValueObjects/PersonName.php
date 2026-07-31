<?php

declare(strict_types=1);

namespace Modules\Core\Person\ValueObjects;

use InvalidArgumentException;

final readonly class PersonName
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                'Person name cannot be empty.',
            );
        }

        if (mb_strlen($value) > 255) {
            throw new InvalidArgumentException(
                'Person name cannot exceed 255 characters.',
            );
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
