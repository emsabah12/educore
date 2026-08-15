<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
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

final class ResidentPlacementPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_planned_placement_is_uuidv7_tenant_owned_membership_room_fact(): void
    {
        $tenant = $this->createTenant(
            'Placement Tenant',
            'placement-tenant',
        );
        $membership = $this->createMembership($tenant, 'Resident One');
        $this->activateTenant($tenant);

        [$room] = $this->createRoomWithResources('Placement Room');

        $placement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now(),
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $placement->getKey()),
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $placement->tenant_id,
        );
        $this->assertSame(
            (string) $membership->getKey(),
            (string) $placement->membership->getKey(),
        );
        $this->assertSame(
            (string) $room->getKey(),
            (string) $placement->room->getKey(),
        );
        $this->assertNull($placement->bed_id);
        $this->assertNull($placement->locker_id);
        $this->assertSame(
            ResidentCategory::REGULAR_RESIDENT,
            $placement->resident_category,
        );
        $this->assertSame(
            PlacementStatus::PLANNED,
            $placement->status,
        );
        $this->assertNotNull($placement->planned_at);
        $this->assertNull($placement->checked_in_at);
        $this->assertNull($placement->ended_at);
        $this->assertNull($placement->cancelled_at);
    }

    public function test_active_placement_can_reference_resources_from_its_room(): void
    {
        $tenant = $this->createTenant(
            'Active Placement Tenant',
            'active-placement-tenant',
        );
        $membership = $this->createMembership($tenant, 'Active Resident');
        $this->activateTenant($tenant);

        [$room, $bed, $locker] = $this->createRoomWithResources('Active Room');

        $placement = $this->createActivePlacement(
            $membership,
            $room,
            $bed,
            $locker,
            ResidentCategory::SUPERVISOR_RESIDENT,
        );

        $this->assertSame(
            ResidentCategory::SUPERVISOR_RESIDENT,
            $placement->resident_category,
        );
        $this->assertSame(
            PlacementStatus::ACTIVE,
            $placement->status,
        );
        $this->assertSame(
            (string) $bed->getKey(),
            (string) $placement->bed->getKey(),
        );
        $this->assertSame(
            (string) $locker->getKey(),
            (string) $placement->locker->getKey(),
        );
        $this->assertNotNull($placement->checked_in_at);
        $this->assertNull($placement->ended_at);
        $this->assertNull($placement->cancelled_at);
    }

    public function test_placement_does_not_duplicate_inherited_identity_or_location_columns(): void
    {
        $this->assertTrue(Schema::hasTable('resident_placements'));

        foreach ([
            'student_id',
            'building_id',
            'dormitory_id',
            'organization_id',
            'organization_unit_id',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('resident_placements', $column),
            );
        }
    }

    public function test_tenant_scope_isolates_resident_placements(): void
    {
        $tenantA = $this->createTenant(
            'Placement Tenant A',
            'placement-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Placement Tenant B',
            'placement-tenant-b',
        );

        $membershipA = $this->createMembership($tenantA, 'Resident A');
        $membershipB = $this->createMembership($tenantB, 'Resident B');

        $this->activateTenant($tenantA);
        [$roomA] = $this->createRoomWithResources('Room A');
        $placementA = $this->createPlannedPlacement($membershipA, $roomA);

        $this->activateTenant($tenantB);
        [$roomB] = $this->createRoomWithResources('Room B');
        $placementB = $this->createPlannedPlacement($membershipB, $roomB);

        $this->activateTenant($tenantA);

        $this->assertSame(
            [(string) $placementA->getKey()],
            ResidentPlacement::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
        $this->assertFalse(
            ResidentPlacement::query()
                ->whereKey($placementB->getKey())
                ->exists(),
        );
    }

    public function test_database_rejects_membership_from_another_tenant(): void
    {
        $tenantA = $this->createTenant(
            'Membership Tenant A',
            'membership-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Membership Tenant B',
            'membership-tenant-b',
        );
        $membershipB = $this->createMembership($tenantB, 'Foreign Resident');

        $this->activateTenant($tenantA);
        [$roomA] = $this->createRoomWithResources('Membership Room A');

        $this->expectException(QueryException::class);

        ResidentPlacement::query()->create([
            'membership_id' => (string) $membershipB->getKey(),
            'room_id' => (string) $roomA->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now(),
        ]);
    }

    public function test_database_rejects_room_from_another_tenant(): void
    {
        $tenantA = $this->createTenant(
            'Room Tenant A',
            'placement-room-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Room Tenant B',
            'placement-room-tenant-b',
        );
        $membershipA = $this->createMembership($tenantA, 'Room Resident A');

        $this->activateTenant($tenantB);
        [$roomB] = $this->createRoomWithResources('Foreign Room B');

        $this->activateTenant($tenantA);

        $this->expectException(QueryException::class);

        ResidentPlacement::query()->create([
            'membership_id' => (string) $membershipA->getKey(),
            'room_id' => (string) $roomB->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now(),
        ]);
    }

    public function test_database_rejects_bed_from_another_room(): void
    {
        $tenant = $this->createTenant(
            'Bed Ownership Tenant',
            'bed-ownership-tenant',
        );
        $membership = $this->createMembership($tenant, 'Bed Resident');
        $this->activateTenant($tenant);

        [$roomA, , $lockerA] = $this->createRoomWithResources('Bed Room A');
        [, $bedB] = $this->createRoomWithResources('Bed Room B');

        $this->expectException(QueryException::class);

        $this->createActivePlacement(
            $membership,
            $roomA,
            $bedB,
            $lockerA,
        );
    }

    public function test_database_rejects_locker_from_another_room(): void
    {
        $tenant = $this->createTenant(
            'Locker Ownership Tenant',
            'locker-ownership-tenant',
        );
        $membership = $this->createMembership($tenant, 'Locker Resident');
        $this->activateTenant($tenant);

        [$roomA, $bedA] = $this->createRoomWithResources('Locker Room A');
        [, , $lockerB] = $this->createRoomWithResources('Locker Room B');

        $this->expectException(QueryException::class);

        $this->createActivePlacement(
            $membership,
            $roomA,
            $bedA,
            $lockerB,
        );
    }

    public function test_database_allows_history_but_rejects_second_active_placement_for_membership(): void
    {
        $tenant = $this->createTenant(
            'Single Active Tenant',
            'single-active-tenant',
        );
        $membership = $this->createMembership($tenant, 'Single Active Resident');
        $this->activateTenant($tenant);

        [$roomA, $bedA, $lockerA] = $this->createRoomWithResources('History Room A');
        [$roomB, $bedB, $lockerB] = $this->createRoomWithResources('History Room B');

        $this->createEndedPlacement(
            $membership,
            $roomA,
            $bedA,
            $lockerA,
        );

        $active = $this->createActivePlacement(
            $membership,
            $roomB,
            $bedB,
            $lockerB,
        );

        $this->assertSame(2, ResidentPlacement::query()->count());
        $this->assertSame(PlacementStatus::ACTIVE, $active->status);

        [$roomC, $bedC, $lockerC] = $this->createRoomWithResources('History Room C');

        $this->expectException(QueryException::class);

        $this->createActivePlacement(
            $membership,
            $roomC,
            $bedC,
            $lockerC,
        );
    }

    public function test_database_rejects_double_active_bed_allocation(): void
    {
        $tenant = $this->createTenant(
            'Bed Exclusivity Tenant',
            'bed-exclusivity-tenant',
        );
        $membershipA = $this->createMembership($tenant, 'Bed Resident A');
        $membershipB = $this->createMembership($tenant, 'Bed Resident B');
        $this->activateTenant($tenant);

        [$room, $bed, $lockerA] = $this->createRoomWithResources('Bed Exclusive Room');
        $lockerB = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'LOCKER-SECOND',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $this->createActivePlacement(
            $membershipA,
            $room,
            $bed,
            $lockerA,
        );

        $this->expectException(QueryException::class);

        $this->createActivePlacement(
            $membershipB,
            $room,
            $bed,
            $lockerB,
        );
    }

    public function test_database_rejects_double_active_locker_allocation(): void
    {
        $tenant = $this->createTenant(
            'Locker Exclusivity Tenant',
            'locker-exclusivity-tenant',
        );
        $membershipA = $this->createMembership($tenant, 'Locker Resident A');
        $membershipB = $this->createMembership($tenant, 'Locker Resident B');
        $this->activateTenant($tenant);

        [$room, $bedA, $locker] = $this->createRoomWithResources('Locker Exclusive Room');
        $bedB = Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'BED-SECOND',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $this->createActivePlacement(
            $membershipA,
            $room,
            $bedA,
            $locker,
        );

        $this->expectException(QueryException::class);

        $this->createActivePlacement(
            $membershipB,
            $room,
            $bedB,
            $locker,
        );
    }

    public function test_database_rejects_invalid_resident_category(): void
    {
        $tenant = $this->createTenant(
            'Category Constraint Tenant',
            'category-constraint-tenant',
        );
        $membership = $this->createMembership($tenant, 'Category Resident');
        $this->activateTenant($tenant);
        [$room] = $this->createRoomWithResources('Category Room');

        $this->expectException(QueryException::class);

        $this->insertRawPlacement([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => 'INVALID_CATEGORY',
            'status' => PlacementStatus::PLANNED->value,
            'planned_at' => now(),
        ]);
    }

    public function test_database_rejects_invalid_placement_status(): void
    {
        $tenant = $this->createTenant(
            'Status Constraint Tenant',
            'status-constraint-tenant',
        );
        $membership = $this->createMembership($tenant, 'Status Resident');
        $this->activateTenant($tenant);
        [$room] = $this->createRoomWithResources('Status Room');

        $this->expectException(QueryException::class);

        $this->insertRawPlacement([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT->value,
            'status' => 'INVALID_STATUS',
            'planned_at' => now(),
        ]);
    }

    public function test_database_rejects_active_status_without_check_in_timestamp(): void
    {
        $tenant = $this->createTenant(
            'Lifecycle Constraint Tenant',
            'lifecycle-constraint-tenant',
        );
        $membership = $this->createMembership($tenant, 'Lifecycle Resident');
        $this->activateTenant($tenant);
        [$room] = $this->createRoomWithResources('Lifecycle Room');

        $this->expectException(QueryException::class);

        $this->insertRawPlacement([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT->value,
            'status' => PlacementStatus::ACTIVE->value,
            'planned_at' => now(),
            'checked_in_at' => null,
        ]);
    }

    public function test_cancelled_placement_is_historical_without_check_in(): void
    {
        $tenant = $this->createTenant(
            'Cancelled Placement Tenant',
            'cancelled-placement-tenant',
        );
        $membership = $this->createMembership($tenant, 'Cancelled Resident');
        $this->activateTenant($tenant);
        [$room] = $this->createRoomWithResources('Cancelled Room');

        $placement = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::CANCELLED,
            'planned_at' => now()->subHour(),
            'cancelled_at' => now(),
            'cancellation_reason' => 'Resident did not proceed with check-in.',
        ]);

        $this->assertSame(PlacementStatus::CANCELLED, $placement->status);
        $this->assertNull($placement->checked_in_at);
        $this->assertNull($placement->ended_at);
        $this->assertNotNull($placement->cancelled_at);
        $this->assertSame(
            'Resident did not proceed with check-in.',
            $placement->cancellation_reason,
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
            'code' => $name.' BED',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $locker = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => $name.' LOCKER',
            'is_usable' => true,
            'is_active' => true,
        ]);

        return [$room, $bed, $locker];
    }

    private function createPlannedPlacement(
        Membership $membership,
        Room $room,
    ): ResidentPlacement {
        return ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now(),
        ]);
    }

    private function createActivePlacement(
        Membership $membership,
        Room $room,
        Bed $bed,
        Locker $locker,
        ResidentCategory $category = ResidentCategory::REGULAR_RESIDENT,
    ): ResidentPlacement {
        return ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'bed_id' => (string) $bed->getKey(),
            'locker_id' => (string) $locker->getKey(),
            'resident_category' => $category,
            'status' => PlacementStatus::ACTIVE,
            'planned_at' => now()->subHour(),
            'checked_in_at' => now(),
        ]);
    }

    private function createEndedPlacement(
        Membership $membership,
        Room $room,
        Bed $bed,
        Locker $locker,
    ): ResidentPlacement {
        return ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $room->getKey(),
            'bed_id' => (string) $bed->getKey(),
            'locker_id' => (string) $locker->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::ENDED,
            'planned_at' => now()->subDays(2),
            'checked_in_at' => now()->subDay(),
            'ended_at' => now(),
            'end_reason' => 'Historical placement completed.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertRawPlacement(array $attributes): void
    {
        DB::table('resident_placements')->insert(array_merge([
            'id' => UuidV7::generate(),
            'bed_id' => null,
            'locker_id' => null,
            'planned_at' => null,
            'checked_in_at' => null,
            'ended_at' => null,
            'cancelled_at' => null,
            'end_reason' => null,
            'cancellation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
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
