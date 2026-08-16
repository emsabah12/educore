<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Dormitory\Application\Commands\CheckInResident;
use Modules\Dormitory\Application\Contracts\ResidentPlacementServiceInterface;
use Modules\Dormitory\Domain\Enums\PlacementStatus;
use Modules\Dormitory\Domain\Enums\ResidentCategory;
use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;
use Modules\Dormitory\Domain\Exceptions\ResidentCheckInException;
use Modules\Dormitory\Models\Bed;
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\Locker;
use Modules\Dormitory\Models\ResidentPlacement;
use Modules\Dormitory\Models\Room;
use Tests\TestCase;

final class ResidentCheckInServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_activates_matching_planned_placement_with_required_resources(): void
    {
        $tenant = $this->createTenant(
            'Check In Tenant',
            'check-in-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Check In Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Check In Room',
        );

        $plannedAt = now()->subHour()->startOfSecond();

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => $plannedAt,
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        $activatedPlacement = $service->checkIn(
            new CheckInResident(
                membershipId: (string) $membership->getKey(),
                roomId: (string) $room->getKey(),
                bedId: (string) $bed->getKey(),
                lockerId: (string) $locker->getKey(),
                residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
            ),
        );

        $this->assertSame(
            (string) $plannedPlacement->getKey(),
            (string) $activatedPlacement->getKey(),
        );
        $this->assertSame(
            PlacementStatus::ACTIVE,
            $activatedPlacement->status,
        );
        $this->assertSame(
            (string) $bed->getKey(),
            (string) $activatedPlacement->bed_id,
        );
        $this->assertSame(
            (string) $locker->getKey(),
            (string) $activatedPlacement->locker_id,
        );
        $this->assertNotNull($activatedPlacement->checked_in_at);
        $this->assertNull($activatedPlacement->ended_at);
        $this->assertNull($activatedPlacement->cancelled_at);

        $persisted = ResidentPlacement::query()->findOrFail(
            $plannedPlacement->getKey(),
        );

        $this->assertSame(PlacementStatus::ACTIVE, $persisted->status);
        $this->assertSame(
            (string) $bed->getKey(),
            (string) $persisted->bed_id,
        );
        $this->assertSame(
            (string) $locker->getKey(),
            (string) $persisted->locker_id,
        );
        $this->assertNotNull($persisted->checked_in_at);
        $this->assertTrue(
            $persisted->planned_at->equalTo($plannedAt),
        );
        $this->assertSame(
            1,
            ResidentPlacement::query()
                ->where('membership_id', $membership->getKey())
                ->count(),
        );
    }

    public function test_check_in_rejects_bed_already_used_by_another_active_placement(): void
    {
        $tenant = $this->createTenant(
            'Occupied Bed Tenant',
            'occupied-bed-tenant',
        );
        $occupantMembership = $this->createMembership(
            $tenant,
            'Current Bed Occupant',
        );
        $incomingMembership = $this->createMembership(
            $tenant,
            'Incoming Resident',
        );
        $this->activateTenant($tenant);

        [$room, $occupiedBed, $occupiedLocker] = $this->createRoomWithResources(
            'Occupied Bed Room',
        );

        ResidentPlacement::query()->create([
            'membership_id' => (string) $occupantMembership->getKey(),
            'room_id' => (string) $room->getKey(),
            'bed_id' => (string) $occupiedBed->getKey(),
            'locker_id' => (string) $occupiedLocker->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::ACTIVE,
            'planned_at' => now()->subHours(2),
            'checked_in_at' => now()->subHour(),
        ]);

        $availableLocker = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'LOCKER-INCOMING',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $incomingMembership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $incomingMembership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $occupiedBed->getKey(),
                    lockerId: (string) $availableLocker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a bed already used by an ACTIVE placement.',
            );
        } catch (ResidentCheckInException) {
            $this->addToAssertionCount(1);
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_locker_already_used_by_another_active_placement(): void
    {
        $tenant = $this->createTenant(
            'Occupied Locker Tenant',
            'occupied-locker-tenant',
        );
        $occupantMembership = $this->createMembership(
            $tenant,
            'Current Locker Occupant',
        );
        $incomingMembership = $this->createMembership(
            $tenant,
            'Incoming Locker Resident',
        );
        $this->activateTenant($tenant);

        [$room, $occupiedBed, $occupiedLocker] = $this->createRoomWithResources(
            'Occupied Locker Room',
        );

        ResidentPlacement::query()->create([
            'membership_id' => (string) $occupantMembership->getKey(),
            'room_id' => (string) $room->getKey(),
            'bed_id' => (string) $occupiedBed->getKey(),
            'locker_id' => (string) $occupiedLocker->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::ACTIVE,
            'planned_at' => now()->subHours(2),
            'checked_in_at' => now()->subHour(),
        ]);

        $availableBed = Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'BED-INCOMING',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $incomingMembership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $incomingMembership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $availableBed->getKey(),
                    lockerId: (string) $occupiedLocker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a locker already used by an ACTIVE placement.',
            );
        } catch (ResidentCheckInException) {
            $this->addToAssertionCount(1);
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_room_when_building_is_inactive(): void
    {
        $tenant = $this->createTenant(
            'Inactive Building Tenant',
            'inactive-building-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Inactive Building Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Inactive Building Room',
        );

        $building = $room->building;
        $building->is_active = false;
        $building->saveOrFail();

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a room whose Building is inactive.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The target room is unavailable in the current tenant.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_room_when_dormitory_is_inactive(): void
    {
        $tenant = $this->createTenant(
            'Inactive Dormitory Tenant',
            'inactive-dormitory-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Inactive Dormitory Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Inactive Dormitory Room',
        );

        $building = $room->building;
        $dormitory = Dormitory::query()->findOrFail(
            $building->dormitory_id,
        );
        $dormitory->is_active = false;
        $dormitory->saveOrFail();

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a room whose Dormitory is inactive.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The target room is unavailable in the current tenant.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_membership_that_becomes_inactive_before_check_in(): void
    {
        $tenant = $this->createTenant(
            'Inactive Membership Tenant',
            'inactive-membership-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Inactive Membership Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Inactive Membership Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $membership->status = 'INACTIVE';
        $membership->saveOrFail();

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a membership that is no longer ACTIVE.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'Resident membership is not eligible in the current tenant.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_membership_that_already_has_active_placement(): void
    {
        $tenant = $this->createTenant(
            'Existing Active Placement Tenant',
            'existing-active-placement-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Existing Active Placement Resident',
        );
        $this->activateTenant($tenant);

        [$currentRoom, $currentBed, $currentLocker] = $this->createRoomWithResources(
            'Current Active Placement Room',
        );
        [$targetRoom, $targetBed, $targetLocker] = $this->createRoomWithResources(
            'Target Planned Placement Room',
        );

        $activePlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $currentRoom->getKey(),
            'bed_id' => (string) $currentBed->getKey(),
            'locker_id' => (string) $currentLocker->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::ACTIVE,
            'planned_at' => now()->subHours(2),
            'checked_in_at' => now()->subHour(),
        ]);

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $targetRoom->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $targetRoom->getKey(),
                    bedId: (string) $targetBed->getKey(),
                    lockerId: (string) $targetLocker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a membership that already has an ACTIVE placement.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The membership already has an active resident placement.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();
        $activePlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
        $this->assertSame(
            PlacementStatus::ACTIVE,
            $activePlacement->status,
        );
        $this->assertSame(
            (string) $currentBed->getKey(),
            (string) $activePlacement->bed_id,
        );
        $this->assertSame(
            (string) $currentLocker->getKey(),
            (string) $activePlacement->locker_id,
        );
    }

    public function test_check_in_rejects_when_matching_planned_placement_does_not_exist(): void
    {
        $tenant = $this->createTenant(
            'Missing Planned Placement Tenant',
            'missing-planned-placement-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Missing Planned Placement Resident',
        );
        $this->activateTenant($tenant);

        [$plannedRoom] = $this->createRoomWithResources(
            'Existing Planned Placement Room',
        );
        [$targetRoom, $targetBed, $targetLocker] = $this->createRoomWithResources(
            'Missing Planned Placement Target Room',
        );

        $existingPlannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $plannedRoom->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $targetRoom->getKey(),
                    bedId: (string) $targetBed->getKey(),
                    lockerId: (string) $targetLocker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject when no matching PLANNED placement exists.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'A matching planned resident placement was not found.',
                $exception->getMessage(),
            );
        }

        $existingPlannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $existingPlannedPlacement->status,
        );
        $this->assertNull($existingPlannedPlacement->bed_id);
        $this->assertNull($existingPlannedPlacement->locker_id);
        $this->assertNull($existingPlannedPlacement->checked_in_at);
        $this->assertSame(
            1,
            ResidentPlacement::query()
                ->where('membership_id', $membership->getKey())
                ->count(),
        );
    }

    public function test_check_in_rejects_room_that_becomes_inactive_before_check_in(): void
    {
        $tenant = $this->createTenant(
            'Inactive Room Tenant',
            'inactive-room-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Inactive Room Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Inactive Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $room->is_active = false;
        $room->saveOrFail();

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a room that is no longer ACTIVE.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The target room is unavailable in the current tenant.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_bed_that_becomes_inactive_before_check_in(): void
    {
        $tenant = $this->createTenant(
            'Inactive Bed Tenant',
            'inactive-bed-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Inactive Bed Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Inactive Bed Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $bed->is_active = false;
        $bed->saveOrFail();

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a bed that is no longer ACTIVE.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The selected bed is unavailable for the target room.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_bed_that_becomes_unusable_before_check_in(): void
    {
        $tenant = $this->createTenant(
            'Unusable Bed Tenant',
            'unusable-bed-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Unusable Bed Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Unusable Bed Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $bed->is_usable = false;
        $bed->saveOrFail();

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a bed that is no longer usable.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The selected bed is unavailable for the target room.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_locker_that_becomes_inactive_before_check_in(): void
    {
        $tenant = $this->createTenant(
            'Inactive Locker Tenant',
            'inactive-locker-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Inactive Locker Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Inactive Locker Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $locker->is_active = false;
        $locker->saveOrFail();

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a locker that is no longer ACTIVE.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The selected locker is unavailable for the target room.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_locker_that_becomes_unusable_before_check_in(): void
    {
        $tenant = $this->createTenant(
            'Unusable Locker Tenant',
            'unusable-locker-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Unusable Locker Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources(
            'Unusable Locker Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $locker->is_usable = false;
        $locker->saveOrFail();

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject a locker that is no longer usable.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The selected locker is unavailable for the target room.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_missing_required_locker_for_bed_and_locker_room(): void
    {
        $tenant = $this->createTenant(
            'Missing Required Locker Tenant',
            'missing-required-locker-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Missing Required Locker Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed] = $this->createRoomWithResources(
            'Missing Required Locker Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: null,
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject BED_AND_LOCKER room without a locker.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The selected resources do not satisfy the room capacity basis.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_rejects_missing_required_bed_for_bed_and_locker_room(): void
    {
        $tenant = $this->createTenant(
            'Missing Required Bed Tenant',
            'missing-required-bed-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Missing Required Bed Resident',
        );
        $this->activateTenant($tenant);

        [$room, , $locker] = $this->createRoomWithResources(
            'Missing Required Bed Room',
        );

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: null,
                    lockerId: (string) $locker->getKey(),
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must reject BED_AND_LOCKER room without a bed.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The selected resources do not satisfy the room capacity basis.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    public function test_check_in_revalidates_current_room_capacity_basis(): void
    {
        $tenant = $this->createTenant(
            'Capacity Basis Revalidation Tenant',
            'capacity-basis-revalidation-tenant',
        );
        $membership = $this->createMembership(
            $tenant,
            'Capacity Basis Revalidation Resident',
        );
        $this->activateTenant($tenant);

        [$room, $bed] = $this->createRoomWithResources(
            'Capacity Basis Revalidation Room',
        );

        $room->capacity_basis = RoomCapacityBasis::BED;
        $room->saveOrFail();

        $plannedPlacement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(30),
        ]);

        $room->capacity_basis = RoomCapacityBasis::BED_AND_LOCKER;
        $room->saveOrFail();

        $service = $this->app->make(
            ResidentPlacementServiceInterface::class,
        );

        try {
            $service->checkIn(
                new CheckInResident(
                    membershipId: (string) $membership->getKey(),
                    roomId: (string) $room->getKey(),
                    bedId: (string) $bed->getKey(),
                    lockerId: null,
                    residentCategory: ResidentCategory::REGULAR_RESIDENT->value,
                ),
            );

            $this->fail(
                'Check-in must use the current room capacity basis.',
            );
        } catch (ResidentCheckInException $exception) {
            $this->assertSame(
                'The selected resources do not satisfy the room capacity basis.',
                $exception->getMessage(),
            );
        }

        $plannedPlacement->refresh();

        $this->assertSame(
            PlacementStatus::PLANNED,
            $plannedPlacement->status,
        );
        $this->assertNull($plannedPlacement->bed_id);
        $this->assertNull($plannedPlacement->locker_id);
        $this->assertNull($plannedPlacement->checked_in_at);
    }

    /**
     * @return array{0: Room, 1: Bed, 2: Locker}
     */
    private function createRoomWithResources(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name.' Organization',
            'is_active' => true,
        ]);

        $dormitory = Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => $name.' Dormitory',
            'is_active' => true,
        ]);

        $building = Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => $name.' Building',
            'is_active' => true,
        ]);

        $room = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => $name,
            'capacity_basis' => RoomCapacityBasis::BED_AND_LOCKER,
            'is_active' => true,
        ]);

        $bed = Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'BED-CHECK-IN',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $locker = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'LOCKER-CHECK-IN',
            'is_usable' => true,
            'is_active' => true,
        ]);

        return [$room, $bed, $locker];
    }

    private function createMembership(
        Tenant $tenant,
        string $personName,
    ): Membership {
        $person = PersonModel::query()->create([
            'name' => $personName,
            'status' => 'ACTIVE',
        ]);

        return Membership::query()->create([
            'person_id' => (string) $person->getKey(),
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);
    }

    private function createTenant(
        string $name,
        string $subdomain,
    ): Tenant {
        return Tenant::query()->create([
            'name' => $name,
            'subdomain' => $subdomain,
            'is_active' => true,
        ]);
    }

    private function activateTenant(Tenant $tenant): void
    {
        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);
    }
}
