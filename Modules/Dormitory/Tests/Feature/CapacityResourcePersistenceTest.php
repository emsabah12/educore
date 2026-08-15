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
use Modules\Dormitory\Models\Bed;
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\Locker;
use Modules\Dormitory\Models\Room;
use Tests\TestCase;

final class CapacityResourcePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bed_and_locker_are_uuidv7_tenant_owned_room_resources(): void
    {
        $tenant = $this->createTenant(
            'Capacity Resource Tenant',
            'capacity-resource-tenant',
        );
        $this->activateTenant($tenant);

        $room = $this->createRoom('Resource Room');

        $bed = Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'BED-01',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $locker = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'LOCKER-01',
            'is_usable' => false,
            'is_active' => true,
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $bed->getKey()),
        );
        $this->assertTrue(
            UuidV7::validate((string) $locker->getKey()),
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $bed->tenant_id,
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $locker->tenant_id,
        );
        $this->assertSame(
            (string) $room->getKey(),
            (string) $bed->room->getKey(),
        );
        $this->assertSame(
            (string) $room->getKey(),
            (string) $locker->room->getKey(),
        );
        $this->assertTrue($bed->is_usable);
        $this->assertFalse($locker->is_usable);
    }

    public function test_bed_and_locker_do_not_duplicate_inherited_ownership_columns(): void
    {
        $this->assertTrue(Schema::hasTable('beds'));
        $this->assertTrue(Schema::hasTable('lockers'));

        foreach (['beds', 'lockers'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'building_id'),
            );
            $this->assertFalse(
                Schema::hasColumn($table, 'dormitory_id'),
            );
            $this->assertFalse(
                Schema::hasColumn($table, 'organization_id'),
            );
            $this->assertFalse(
                Schema::hasColumn($table, 'organization_unit_id'),
            );
        }
    }

    public function test_tenant_scope_isolates_beds_and_lockers(): void
    {
        $tenantA = $this->createTenant(
            'Capacity Tenant A',
            'capacity-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Capacity Tenant B',
            'capacity-tenant-b',
        );

        $this->activateTenant($tenantA);
        $roomA = $this->createRoom('Room A');
        [$bedA, $lockerA] = $this->createResources($roomA, 'A');

        $this->activateTenant($tenantB);
        $roomB = $this->createRoom('Room B');
        [$bedB, $lockerB] = $this->createResources($roomB, 'B');

        $this->activateTenant($tenantA);

        $this->assertSame(
            [(string) $bedA->getKey()],
            Bed::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
        $this->assertSame(
            [(string) $lockerA->getKey()],
            Locker::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
        $this->assertFalse(
            Bed::query()->whereKey($bedB->getKey())->exists(),
        );
        $this->assertFalse(
            Locker::query()->whereKey($lockerB->getKey())->exists(),
        );
    }

    public function test_database_rejects_bed_from_another_tenant_room(): void
    {
        $tenantA = $this->createTenant(
            'Bed Tenant A',
            'bed-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Bed Tenant B',
            'bed-tenant-b',
        );

        $this->activateTenant($tenantB);
        $roomB = $this->createRoom('Tenant B Bed Room');

        $this->activateTenant($tenantA);

        $this->expectException(QueryException::class);

        Bed::query()->create([
            'room_id' => (string) $roomB->getKey(),
            'code' => 'INVALID-BED',
            'is_usable' => true,
            'is_active' => true,
        ]);
    }

    public function test_database_rejects_locker_from_another_tenant_room(): void
    {
        $tenantA = $this->createTenant(
            'Locker Tenant A',
            'locker-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Locker Tenant B',
            'locker-tenant-b',
        );

        $this->activateTenant($tenantB);
        $roomB = $this->createRoom('Tenant B Locker Room');

        $this->activateTenant($tenantA);

        $this->expectException(QueryException::class);

        Locker::query()->create([
            'room_id' => (string) $roomB->getKey(),
            'code' => 'INVALID-LOCKER',
            'is_usable' => true,
            'is_active' => true,
        ]);
    }

    public function test_hard_delete_of_room_with_beds_is_restricted(): void
    {
        $tenant = $this->createTenant(
            'Bed Restrict Tenant',
            'bed-restrict-tenant',
        );
        $this->activateTenant($tenant);

        $room = $this->createRoom('Protected Bed Room');

        Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'PROTECTED-BED',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        $room->forceDelete();
    }

    public function test_hard_delete_of_room_with_lockers_is_restricted(): void
    {
        $tenant = $this->createTenant(
            'Locker Restrict Tenant',
            'locker-restrict-tenant',
        );
        $this->activateTenant($tenant);

        $room = $this->createRoom('Protected Locker Room');

        Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'PROTECTED-LOCKER',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        $room->forceDelete();
    }

    /**
     * @return array{0: Bed, 1: Locker}
     */
    private function createResources(Room $room, string $suffix): array
    {
        $bed = Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'BED-'.$suffix,
            'is_usable' => true,
            'is_active' => true,
        ]);

        $locker = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'LOCKER-'.$suffix,
            'is_usable' => true,
            'is_active' => true,
        ]);

        return [$bed, $locker];
    }

    private function createRoom(string $name): Room
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

        return Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => $name,
            'capacity_basis' => RoomCapacityBasis::BED_AND_LOCKER,
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
