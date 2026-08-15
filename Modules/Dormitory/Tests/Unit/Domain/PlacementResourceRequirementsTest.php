<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Unit\Domain;

use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;
use Modules\Dormitory\Domain\ValueObjects\PlacementResourceRequirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlacementResourceRequirementsTest extends TestCase
{
    public function test_bed_basis_requires_only_bed(): void
    {
        $requirements = PlacementResourceRequirements::fromBasis(
            RoomCapacityBasis::BED,
        );

        $this->assertTrue($requirements->requiresBed());
        $this->assertFalse($requirements->requiresLocker());
        $this->assertTrue($requirements->isSatisfiedBy(
            hasBed: true,
            hasLocker: false,
        ));
        $this->assertTrue($requirements->isSatisfiedBy(
            hasBed: true,
            hasLocker: true,
        ));
        $this->assertFalse($requirements->isSatisfiedBy(
            hasBed: false,
            hasLocker: false,
        ));
    }

    public function test_locker_basis_requires_only_locker(): void
    {
        $requirements = PlacementResourceRequirements::fromBasis(
            RoomCapacityBasis::LOCKER,
        );

        $this->assertFalse($requirements->requiresBed());
        $this->assertTrue($requirements->requiresLocker());
        $this->assertTrue($requirements->isSatisfiedBy(
            hasBed: false,
            hasLocker: true,
        ));
        $this->assertTrue($requirements->isSatisfiedBy(
            hasBed: true,
            hasLocker: true,
        ));
        $this->assertFalse($requirements->isSatisfiedBy(
            hasBed: false,
            hasLocker: false,
        ));
    }

    #[DataProvider('bedAndLockerCombinations')]
    public function test_bed_and_locker_basis_requires_both_resources(
        bool $hasBed,
        bool $hasLocker,
        bool $expected,
    ): void {
        $requirements = PlacementResourceRequirements::fromBasis(
            RoomCapacityBasis::BED_AND_LOCKER,
        );

        $this->assertTrue($requirements->requiresBed());
        $this->assertTrue($requirements->requiresLocker());
        $this->assertSame(
            $expected,
            $requirements->isSatisfiedBy(
                hasBed: $hasBed,
                hasLocker: $hasLocker,
            ),
        );
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function bedAndLockerCombinations(): iterable
    {
        yield 'both resources present' => [true, true, true];
        yield 'bed only' => [true, false, false];
        yield 'locker only' => [false, true, false];
        yield 'neither resource present' => [false, false, false];
    }
}
