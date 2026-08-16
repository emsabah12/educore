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
