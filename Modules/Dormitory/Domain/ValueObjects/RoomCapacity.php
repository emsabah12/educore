<?php

declare(strict_types=1);

namespace Modules\Dormitory\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;

final readonly class RoomCapacity
{
    public function __construct(
        private RoomCapacityBasis $basis,
        private int $usableBeds,
        private int $usableLockers,
        private int $activeOccupancy,
    ) {
        if ($usableBeds < 0 || $usableLockers < 0 || $activeOccupancy < 0) {
            throw new InvalidArgumentException('Capacity counts cannot be negative.');
        }
    }

    public function basis(): RoomCapacityBasis
    {
        return $this->basis;
    }

    public function usableBeds(): int
    {
        return $this->usableBeds;
    }

    public function usableLockers(): int
    {
        return $this->usableLockers;
    }

    public function activeOccupancy(): int
    {
        return $this->activeOccupancy;
    }

    public function effectiveCapacity(): int
    {
        return match ($this->basis) {
            RoomCapacityBasis::BED => $this->usableBeds,
            RoomCapacityBasis::LOCKER => $this->usableLockers,
            RoomCapacityBasis::BED_AND_LOCKER => min($this->usableBeds, $this->usableLockers),
        };
    }

    public function availableCapacity(): int
    {
        return max(0, $this->effectiveCapacity() - $this->activeOccupancy);
    }

    public function isOverCapacity(): bool
    {
        return $this->activeOccupancy > $this->effectiveCapacity();
    }

    public function overCapacityBy(): int
    {
        return max(0, $this->activeOccupancy - $this->effectiveCapacity());
    }
}
