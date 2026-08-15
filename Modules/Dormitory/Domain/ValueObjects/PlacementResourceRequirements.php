<?php

declare(strict_types=1);

namespace Modules\Dormitory\Domain\ValueObjects;

use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;

final readonly class PlacementResourceRequirements
{
    private function __construct(
        private bool $requiresBed,
        private bool $requiresLocker,
    ) {}

    public static function fromBasis(RoomCapacityBasis $basis): self
    {
        return match ($basis) {
            RoomCapacityBasis::BED => new self(
                requiresBed: true,
                requiresLocker: false,
            ),
            RoomCapacityBasis::LOCKER => new self(
                requiresBed: false,
                requiresLocker: true,
            ),
            RoomCapacityBasis::BED_AND_LOCKER => new self(
                requiresBed: true,
                requiresLocker: true,
            ),
        };
    }

    public function requiresBed(): bool
    {
        return $this->requiresBed;
    }

    public function requiresLocker(): bool
    {
        return $this->requiresLocker;
    }

    public function isSatisfiedBy(
        bool $hasBed,
        bool $hasLocker,
    ): bool {
        return (! $this->requiresBed || $hasBed)
            && (! $this->requiresLocker || $hasLocker);
    }
}
