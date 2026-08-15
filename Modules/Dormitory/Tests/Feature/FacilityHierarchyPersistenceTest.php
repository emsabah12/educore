<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\Room;
use Tests\TestCase;

final class FacilityHierarchyPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_building_and_room_are_uuidv7_tenant_owned_descendants(): void
    {
        $tenant = $this->createTenant(
            'Facility Tenant',
            'facility-tenant',
        );
        $this->activateTenant($tenant);

        $dormitory = $this->createDormitory('Main Dormitory');

        $building = Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => 'Building A',
            'code' => 'A',
            'is_active' => true,
        ]);

        $room = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => 'Room 101',
            'code' => '101',
            'capacity_basis' => RoomCapacityBasis::BED_AND_LOCKER,
            'is_active' => true,
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $building->getKey()),
        );
        $this->assertTrue(
            UuidV7::validate((string) $room->getKey()),
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $building->tenant_id,
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $room->tenant_id,
        );
        $this->assertSame(
            (string) $dormitory->getKey(),
            (string) $building->dormitory->getKey(),
        );
        $this->assertSame(
            (string) $building->getKey(),
            (string) $room->building->getKey(),
        );
        $this->assertSame(
            RoomCapacityBasis::BED_AND_LOCKER,
            $room->capacity_basis,
        );
    }

    public function test_building_and_room_do_not_duplicate_inherited_ownership_columns(): void
    {
        $this->assertTrue(Schema::hasTable('buildings'));
        $this->assertTrue(Schema::hasTable('rooms'));

        $this->assertFalse(
            Schema::hasColumn('buildings', 'organization_id'),
        );
        $this->assertFalse(
            Schema::hasColumn('buildings', 'organization_unit_id'),
        );

        $this->assertFalse(
            Schema::hasColumn('rooms', 'dormitory_id'),
        );
        $this->assertFalse(
            Schema::hasColumn('rooms', 'organization_id'),
        );
        $this->assertFalse(
            Schema::hasColumn('rooms', 'organization_unit_id'),
        );
    }

    public function test_tenant_scope_isolates_buildings_and_rooms(): void
    {
        $tenantA = $this->createTenant(
            'Facility Tenant A',
            'facility-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Facility Tenant B',
            'facility-tenant-b',
        );

        $this->activateTenant($tenantA);
        $dormitoryA = $this->createDormitory('Dormitory A');
        [$buildingA, $roomA] = $this->createFacility(
            $dormitoryA,
            'Building A',
            'Room A',
        );

        $this->activateTenant($tenantB);
        $dormitoryB = $this->createDormitory('Dormitory B');
        [$buildingB, $roomB] = $this->createFacility(
            $dormitoryB,
            'Building B',
            'Room B',
        );

        $this->activateTenant($tenantA);

        $this->assertSame(
            [(string) $buildingA->getKey()],
            Building::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
        $this->assertSame(
            [(string) $roomA->getKey()],
            Room::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
        $this->assertFalse(
            Building::query()->whereKey($buildingB->getKey())->exists(),
        );
        $this->assertFalse(
            Room::query()->whereKey($roomB->getKey())->exists(),
        );
    }

    public function test_database_rejects_building_from_another_tenant_dormitory(): void
    {
        $tenantA = $this->createTenant(
            'Building Tenant A',
            'building-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Building Tenant B',
            'building-tenant-b',
        );

        $this->activateTenant($tenantB);
        $dormitoryB = $this->createDormitory('Tenant B Dormitory');

        $this->activateTenant($tenantA);

        $this->expectException(QueryException::class);

        Building::query()->create([
            'dormitory_id' => (string) $dormitoryB->getKey(),
            'name' => 'Invalid Building',
            'is_active' => true,
        ]);
    }

    public function test_database_rejects_room_from_another_tenant_building(): void
    {
        $tenantA = $this->createTenant(
            'Room Tenant A',
            'room-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Room Tenant B',
            'room-tenant-b',
        );

        $this->activateTenant($tenantB);
        $dormitoryB = $this->createDormitory('Tenant B Dormitory');
        $buildingB = Building::query()->create([
            'dormitory_id' => (string) $dormitoryB->getKey(),
            'name' => 'Tenant B Building',
            'is_active' => true,
        ]);

        $this->activateTenant($tenantA);

        $this->expectException(QueryException::class);

        Room::query()->create([
            'building_id' => (string) $buildingB->getKey(),
            'name' => 'Invalid Room',
            'capacity_basis' => RoomCapacityBasis::BED,
            'is_active' => true,
        ]);
    }

    public function test_hard_delete_of_dormitory_with_buildings_is_restricted(): void
    {
        $tenant = $this->createTenant(
            'Dormitory Restrict Tenant',
            'dormitory-restrict-tenant',
        );
        $this->activateTenant($tenant);

        $dormitory = $this->createDormitory('Protected Dormitory');
        Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => 'Protected Building',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        $dormitory->forceDelete();
    }

    public function test_hard_delete_of_building_with_rooms_is_restricted(): void
    {
        $tenant = $this->createTenant(
            'Building Restrict Tenant',
            'building-restrict-tenant',
        );
        $this->activateTenant($tenant);

        $dormitory = $this->createDormitory('Protected Dormitory');
        [$building] = $this->createFacility(
            $dormitory,
            'Protected Building',
            'Protected Room',
        );

        $this->expectException(QueryException::class);

        $building->forceDelete();
    }

    /**
     * @return array{0: Building, 1: Room}
     */
    private function createFacility(
        Dormitory $dormitory,
        string $buildingName,
        string $roomName,
    ): array {
        $building = Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => $buildingName,
            'is_active' => true,
        ]);

        $room = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => $roomName,
            'capacity_basis' => RoomCapacityBasis::BED_AND_LOCKER,
            'is_active' => true,
        ]);

        return [$building, $room];
    }

    private function createDormitory(string $name): Dormitory
    {
        $organization = Organization::query()->create([
            'name' => $name.' Organization',
            'is_active' => true,
        ]);

        return Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => $name,
            'is_active' => true,
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
