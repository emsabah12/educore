<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Dormitory\Application\Commands\CheckInResident;
use Modules\Dormitory\Application\Services\ResidentPlacementService;
use Modules\Dormitory\Contracts\ResidentEligibilityCheckerInterface;
use Modules\Dormitory\Contracts\ResidentPlacementRepositoryInterface;
use Modules\Dormitory\Contracts\RoomRepositoryInterface;
use Modules\Dormitory\Domain\Enums\PlacementStatus;
use Modules\Dormitory\Domain\Enums\ResidentCategory;
use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;
use Modules\Dormitory\Domain\Exceptions\ResidentCheckInException;
use Modules\Dormitory\Models\Bed;
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\ResidentPlacement;
use Modules\Dormitory\Models\Room;
use PDOException;
use Tests\TestCase;

final class ResidentPlacementUniqueConflictTranslationTest extends TestCase
{
    public function test_check_in_translates_active_membership_unique_conflict(): void
    {
        [$service, $command] = $this->makeCheckInScenario(
            'uq_resident_placements_active_membership',
            ['tenant_id', 'membership_id'],
        );

        try {
            $service->checkIn($command);
            $this->fail('The active-membership unique conflict must be translated.');
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The membership already has an active resident placement.',
                $exception->getMessage(),
            );
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_check_in_translates_active_membership_unique_conflict_by_columns_when_index_is_unavailable(): void
    {
        [$service, $command] = $this->makeCheckInScenario(
            null,
            ['tenant_id', 'membership_id'],
        );

        try {
            $service->checkIn($command);
            $this->fail('The active-membership column signature must be translated.');
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The membership already has an active resident placement.',
                $exception->getMessage(),
            );
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_check_in_translates_active_membership_unique_conflict_by_driver_message_when_metadata_is_unavailable(): void
    {
        [$service, $command] = $this->makeCheckInScenario(
            null,
            [],
            'ERROR: nilai kunci ganda melanggar batasan unik « uq_resident_placements_active_membership »',
        );

        try {
            $service->checkIn($command);
            $this->fail('The active-membership driver constraint token must be translated.');
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The membership already has an active resident placement.',
                $exception->getMessage(),
            );
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_check_in_rethrows_unrelated_unique_conflict(): void
    {
        [$service, $command, $uniqueViolation] = $this->makeCheckInScenario(
            'uq_resident_placements_active_bed',
            ['tenant_id', 'bed_id'],
        );

        try {
            $service->checkIn($command);
            $this->fail('Unrelated unique violations must not be translated.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($uniqueViolation, $exception);
            $this->assertSame(
                'uq_resident_placements_active_bed',
                $exception->index,
            );
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_check_in_rethrows_unrelated_unique_conflict_by_columns_when_index_is_unavailable(): void
    {
        [$service, $command, $uniqueViolation] = $this->makeCheckInScenario(
            null,
            ['tenant_id', 'bed_id'],
        );

        try {
            $service->checkIn($command);
            $this->fail('Unrelated column signatures must not be translated.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($uniqueViolation, $exception);
            $this->assertNull($exception->index);
            $this->assertSame(
                ['tenant_id', 'bed_id'],
                $exception->columns,
            );
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_check_in_rethrows_unrelated_unique_conflict_by_driver_message_when_metadata_is_unavailable(): void
    {
        [$service, $command, $uniqueViolation] = $this->makeCheckInScenario(
            null,
            [],
            'ERROR: nilai kunci ganda melanggar batasan unik « uq_resident_placements_active_bed »',
        );

        try {
            $service->checkIn($command);
            $this->fail('Unrelated driver constraint tokens must not be translated.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($uniqueViolation, $exception);
            $this->assertNull($exception->index);
            $this->assertSame([], $exception->columns);
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    /**
     * @return array{
     *     0: ResidentPlacementService,
     *     1: CheckInResident,
     *     2: UniqueConstraintViolationException
     * }
     */
    private function makeCheckInScenario(
        ?string $uniqueIndex,
        array $uniqueColumns,
        ?string $driverMessage = null,
    ): array {
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $dormitoryId = UuidV7::generate();
        $buildingId = UuidV7::generate();
        $roomId = UuidV7::generate();
        $bedId = UuidV7::generate();

        $room = new Room;
        $room->forceFill([
            'id' => $roomId,
            'tenant_id' => $tenantId,
            'building_id' => $buildingId,
            'capacity_basis' => RoomCapacityBasis::BED,
            'is_active' => true,
        ]);

        $building = new Building;
        $building->forceFill([
            'id' => $buildingId,
            'tenant_id' => $tenantId,
            'dormitory_id' => $dormitoryId,
            'is_active' => true,
        ]);

        $dormitory = new Dormitory;
        $dormitory->forceFill([
            'id' => $dormitoryId,
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]);

        $bed = new Bed;
        $bed->forceFill([
            'id' => $bedId,
            'tenant_id' => $tenantId,
            'room_id' => $roomId,
            'is_active' => true,
            'is_usable' => true,
        ]);

        $plannedPlacement = new ResidentPlacement;
        $plannedPlacement->forceFill([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'room_id' => $roomId,
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
        ]);

        $uniqueViolation = $this->uniqueViolation(
            $uniqueIndex,
            $uniqueColumns,
            $driverMessage,
        );

        /** @var TenantContextInterface&MockInterface $tenantContext */
        $tenantContext = Mockery::mock(TenantContextInterface::class);
        $tenantContext->shouldReceive('getCurrentTenantId')
            ->once()
            ->andReturn($tenantId);

        /** @var RoomRepositoryInterface&MockInterface $roomRepository */
        $roomRepository = Mockery::mock(RoomRepositoryInterface::class);
        $roomRepository->shouldReceive('findByIdAndTenantForUpdate')
            ->once()
            ->with($roomId, $tenantId)
            ->andReturn($room);
        $roomRepository->shouldReceive('findBuildingForShare')
            ->once()
            ->with($buildingId, $tenantId)
            ->andReturn($building);
        $roomRepository->shouldReceive('findDormitoryForShare')
            ->once()
            ->with($dormitoryId, $tenantId)
            ->andReturn($dormitory);
        $roomRepository->shouldReceive('findBedForUpdate')
            ->once()
            ->with($roomId, $bedId, $tenantId)
            ->andReturn($bed);

        /** @var ResidentPlacementRepositoryInterface&MockInterface $placementRepository */
        $placementRepository = Mockery::mock(
            ResidentPlacementRepositoryInterface::class,
        );
        $placementRepository->shouldReceive('findActiveForMembershipForUpdate')
            ->once()
            ->with($tenantId, $membershipId)
            ->andReturnNull();
        $placementRepository->shouldReceive('findPlannedForMembershipInRoomForUpdate')
            ->once()
            ->with(
                $tenantId,
                $membershipId,
                $roomId,
                ResidentCategory::REGULAR_RESIDENT->value,
            )
            ->andReturn($plannedPlacement);
        $placementRepository->shouldReceive('findActiveForBedForUpdate')
            ->once()
            ->with($tenantId, $bedId)
            ->andReturnNull();
        $placementRepository->shouldReceive('save')
            ->once()
            ->with($plannedPlacement)
            ->andThrow($uniqueViolation);

        /** @var ResidentEligibilityCheckerInterface&MockInterface $eligibilityChecker */
        $eligibilityChecker = Mockery::mock(
            ResidentEligibilityCheckerInterface::class,
        );
        $eligibilityChecker->shouldReceive('assertEligible')
            ->once()
            ->with(
                $tenantId,
                $membershipId,
                ResidentCategory::REGULAR_RESIDENT->value,
            );

        return [
            new ResidentPlacementService(
                $tenantContext,
                $roomRepository,
                $placementRepository,
                $eligibilityChecker,
            ),
            new CheckInResident(
                membershipId: $membershipId,
                roomId: $roomId,
                bedId: $bedId,
                lockerId: null,
                residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
            ),
            $uniqueViolation,
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    private function uniqueViolation(
        ?string $index,
        array $columns,
        ?string $driverMessage = null,
    ): UniqueConstraintViolationException {
        $driverMessage ??= sprintf(
            'duplicate key value violates unique constraint "%s"',
            $index ?? 'unknown_unique_index',
        );

        $previous = new PDOException(
            $driverMessage,
            23505,
        );
        $previous->errorInfo = [
            '23505',
            7,
            $driverMessage,
        ];

        return (new UniqueConstraintViolationException(
            'pgsql',
            'update resident_placements set status = ?',
            [PlacementStatus::ACTIVE->value],
            $previous,
        ))
            ->setIndex($index)
            ->setColumns($columns);
    }
}
