<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Unit\Domain;

use InvalidArgumentException;
use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;
use Modules\Dormitory\Domain\ValueObjects\RoomCapacity;
use PHPUnit\Framework\TestCase;

final class RoomCapacityTest extends TestCase
{
    public function test_bed_basis_uses_usable_beds_as_effective_capacity(): void
    {
        $capacity = new RoomCapacity(
            basis: RoomCapacityBasis::BED,
            usableBeds: 20,
            usableLockers: 16,
            activeOccupancy: 12,
        );

        $this->assertSame(20, $capacity->effectiveCapacity());
        $this->assertSame(8, $capacity->availableCapacity());
        $this->assertFalse($capacity->isOverCapacity());
    }

    public function test_locker_basis_uses_usable_lockers_as_effective_capacity(): void
    {
        $capacity = new RoomCapacity(
            basis: RoomCapacityBasis::LOCKER,
            usableBeds: 20,
            usableLockers: 16,
            activeOccupancy: 12,
        );

        $this->assertSame(16, $capacity->effectiveCapacity());
        $this->assertSame(4, $capacity->availableCapacity());
        $this->assertFalse($capacity->isOverCapacity());
    }

    public function test_bed_and_locker_basis_uses_lower_usable_resource_count_not_sum(): void
    {
        $capacity = new RoomCapacity(
            basis: RoomCapacityBasis::BED_AND_LOCKER,
            usableBeds: 20,
            usableLockers: 16,
            activeOccupancy: 12,
        );

        $this->assertSame(16, $capacity->effectiveCapacity());
        $this->assertSame(4, $capacity->availableCapacity());
    }

    public function test_resource_failure_can_make_room_over_capacity_without_changing_occupancy(): void
    {
        $capacity = new RoomCapacity(
            basis: RoomCapacityBasis::BED_AND_LOCKER,
            usableBeds: 20,
            usableLockers: 16,
            activeOccupancy: 17,
        );

        $this->assertSame(16, $capacity->effectiveCapacity());
        $this->assertSame(17, $capacity->activeOccupancy());
        $this->assertSame(0, $capacity->availableCapacity());
        $this->assertTrue($capacity->isOverCapacity());
        $this->assertSame(1, $capacity->overCapacityBy());
    }

    public function test_capacity_counts_must_not_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Capacity counts cannot be negative.');

        new RoomCapacity(
            basis: RoomCapacityBasis::BED,
            usableBeds: -1,
            usableLockers: 0,
            activeOccupancy: 0,
        );
    }
}
