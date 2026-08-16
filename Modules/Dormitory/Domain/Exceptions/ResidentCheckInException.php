<?php

declare(strict_types=1);

namespace Modules\Dormitory\Domain\Exceptions;

use RuntimeException;

final class ResidentCheckInException extends RuntimeException
{
    public static function invalidIdentifier(string $field): self
    {
        return new self(sprintf(
            'Invalid UUIDv7 identifier for [%s].',
            $field,
        ));
    }

    public static function invalidResidentCategory(): self
    {
        return new self('Invalid resident category.');
    }

    public static function membershipNotEligible(): self
    {
        return new self(
            'Resident membership is not eligible in the current tenant.',
        );
    }

    public static function roomUnavailable(): self
    {
        return new self(
            'The target room is unavailable in the current tenant.',
        );
    }

    public static function plannedPlacementNotFound(): self
    {
        return new self(
            'A matching planned resident placement was not found.',
        );
    }

    public static function activePlacementExists(): self
    {
        return new self(
            'The membership already has an active resident placement.',
        );
    }

    public static function bedUnavailable(): self
    {
        return new self(
            'The selected bed is unavailable for the target room.',
        );
    }

    public static function lockerUnavailable(): self
    {
        return new self(
            'The selected locker is unavailable for the target room.',
        );
    }

    public static function resourceRequirementsNotSatisfied(): self
    {
        return new self(
            'The selected resources do not satisfy the room capacity basis.',
        );
    }
}
