<?php

declare(strict_types=1);

namespace Modules\Core\Identity\ValueObjects;

use InvalidArgumentException;

final class CanonicalUsername
{
    private const MIN_LENGTH = 3;

    private const MAX_LENGTH = 64;

    private const FORMAT_PATTERN = '/\A[a-z0-9][a-z0-9._-]*[a-z0-9]\z/D';

    private function __construct()
    {
    }

    public static function normalizeNullable(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Username must be a string or null.',
            );
        }

        $normalized = strtolower(
            trim($value),
        );

        $length = strlen($normalized);

        if (
            $length < self::MIN_LENGTH
            || $length > self::MAX_LENGTH
        ) {
            throw new InvalidArgumentException(
                'Username must contain between 3 and 64 characters.',
            );
        }

        if (
            preg_match(
                self::FORMAT_PATTERN,
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Username may contain only lowercase letters, digits, dots, underscores, and hyphens, and must start and end with a letter or digit.',
            );
        }

        return $normalized;
    }
}
