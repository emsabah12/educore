<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Person\Models\PersonModel;
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

final class SameBedAndLockerDifferentMembershipConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const SESSION_A = 'dormitory_bed_and_locker_session_a';

    private const SESSION_B = 'dormitory_bed_and_locker_session_b';

    public function test_crossed_bed_and_locker_pairs_serialize_on_room_lock_without_false_rejection(): void
    {
        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Dormitory concurrency contracts require PostgreSQL row-lock semantics.',
        );

        [
            $membershipA,
            $membershipB,
            $building,
            $dormitory,
            $room,
            $bedA,
            $bedB,
            $lockerA,
            $lockerB,
            $placementA,
            $placementB,
        ] = $this->createScenario();

        $sessionA = $this->makeIndependentConnection(self::SESSION_A);
        $sessionB = $this->makeIndependentConnection(self::SESSION_B);

        try {
            $backendA = (int) $sessionA
                ->selectOne('select pg_backend_pid() as pid')
                ->pid;
            $backendB = (int) $sessionB
                ->selectOne('select pg_backend_pid() as pid')
                ->pid;

            $this->assertNotSame(
                $backendA,
                $backendB,
                'The serialization proof must use two independent PostgreSQL sessions.',
            );

            $sessionA->beginTransaction();

            $this->lockCheckInPathBeforeActivation(
                $sessionA,
                $membershipA,
                $building,
                $dormitory,
                $room,
                $bedA,
                $lockerB,
                $placementA,
            );

            $this->assertSame(
                1,
                $this->activatePlacement(
                    $sessionA,
                    $placementA,
                    $bedA,
                    $lockerB,
                ),
            );

            $sessionB->beginTransaction();
            $sessionB->statement(
                "SET LOCAL lock_timeout = '250ms'",
            );

            try {
                $sessionB->table('rooms')
                    ->where('id', $room->getKey())
                    ->where('tenant_id', $membershipB->tenant_id)
                    ->lockForUpdate()
                    ->first();

                $this->fail(
                    'The competing check-in must wait on the room concurrency boundary.',
                );
            } catch (QueryException $exception) {
                $this->assertSame(
                    '55P03',
                    $exception->errorInfo[0] ?? null,
                    'The competing room lock must hit the bounded PostgreSQL lock timeout.',
                );
            } finally {
                if ($sessionB->transactionLevel() > 0) {
                    $sessionB->rollBack();
                }
            }

            $sessionA->commit();

            $sessionB->beginTransaction();

            $this->lockCheckInPathBeforeActivation(
                $sessionB,
                $membershipB,
                $building,
                $dormitory,
                $room,
                $bedB,
                $lockerA,
                $placementB,
            );

            $this->assertSame(
                1,
                $this->activatePlacement(
                    $sessionB,
                    $placementB,
                    $bedB,
                    $lockerA,
                ),
            );

            $sessionB->commit();

            $activePlacements = DB::table('resident_placements')
                ->where('tenant_id', $membershipA->tenant_id)
                ->where('room_id', $room->getKey())
                ->where('status', PlacementStatus::ACTIVE->value)
                ->orderBy('planned_at')
                ->get();

            $this->assertCount(2, $activePlacements);

            $persistedPlacementA = $activePlacements->firstWhere(
                'id',
                $placementA->getKey(),
            );
            $persistedPlacementB = $activePlacements->firstWhere(
                'id',
                $placementB->getKey(),
            );

            $this->assertNotNull($persistedPlacementA);
            $this->assertSame(
                (string) $bedA->getKey(),
                (string) $persistedPlacementA->bed_id,
            );
            $this->assertSame(
                (string) $lockerB->getKey(),
                (string) $persistedPlacementA->locker_id,
            );
            $this->assertNotNull($persistedPlacementA->checked_in_at);

            $this->assertNotNull($persistedPlacementB);
            $this->assertSame(
                (string) $bedB->getKey(),
                (string) $persistedPlacementB->bed_id,
            );
            $this->assertSame(
                (string) $lockerA->getKey(),
                (string) $persistedPlacementB->locker_id,
            );
            $this->assertNotNull($persistedPlacementB->checked_in_at);

            $this->assertSame(
                1,
                DB::table('resident_placements')
                    ->where('tenant_id', $membershipA->tenant_id)
                    ->where('bed_id', $bedA->getKey())
                    ->where('status', PlacementStatus::ACTIVE->value)
                    ->count(),
            );
            $this->assertSame(
                1,
                DB::table('resident_placements')
                    ->where('tenant_id', $membershipA->tenant_id)
                    ->where('bed_id', $bedB->getKey())
                    ->where('status', PlacementStatus::ACTIVE->value)
                    ->count(),
            );
            $this->assertSame(
                1,
                DB::table('resident_placements')
                    ->where('tenant_id', $membershipA->tenant_id)
                    ->where('locker_id', $lockerA->getKey())
                    ->where('status', PlacementStatus::ACTIVE->value)
                    ->count(),
            );
            $this->assertSame(
                1,
                DB::table('resident_placements')
                    ->where('tenant_id', $membershipA->tenant_id)
                    ->where('locker_id', $lockerB->getKey())
                    ->where('status', PlacementStatus::ACTIVE->value)
                    ->count(),
            );
        } finally {
            $this->rollBackAndPurge(
                self::SESSION_B,
                $sessionB,
            );
            $this->rollBackAndPurge(
                self::SESSION_A,
                $sessionA,
            );
        }
    }

    private function lockCheckInPathBeforeActivation(
        Connection $connection,
        Membership $membership,
        Building $building,
        Dormitory $dormitory,
        Room $room,
        Bed $bed,
        Locker $locker,
        ResidentPlacement $placement,
    ): void {
        $this->lockHierarchyAndMembership(
            $connection,
            $membership,
            $building,
            $dormitory,
            $room,
        );

        $this->assertNull(
            $this->findActivePlacementForMembershipForUpdate(
                $connection,
                $membership,
            ),
        );

        $this->lockPlannedPlacement(
            $connection,
            $placement,
        );

        $this->lockBed(
            $connection,
            $placement,
            $room,
            $bed,
        );

        $this->assertNull(
            $this->findActivePlacementForBedForUpdate(
                $connection,
                $membership,
                $bed,
            ),
        );

        $this->lockLocker(
            $connection,
            $placement,
            $room,
            $locker,
        );

        $this->assertNull(
            $this->findActivePlacementForLockerForUpdate(
                $connection,
                $membership,
                $locker,
            ),
        );
    }

    private function lockHierarchyAndMembership(
        Connection $connection,
        Membership $membership,
        Building $building,
        Dormitory $dormitory,
        Room $room,
    ): void {
        $lockedRoom = $connection->table('rooms')
            ->where('id', $room->getKey())
            ->where('tenant_id', $membership->tenant_id)
            ->lockForUpdate()
            ->first();
        $lockedBuilding = $connection->table('buildings')
            ->where('id', $building->getKey())
            ->where('tenant_id', $membership->tenant_id)
            ->sharedLock()
            ->first();
        $lockedDormitory = $connection->table('dormitories')
            ->where('id', $dormitory->getKey())
            ->where('tenant_id', $membership->tenant_id)
            ->sharedLock()
            ->first();
        $lockedMembership = $connection->table('memberships')
            ->where('id', $membership->getKey())
            ->where('tenant_id', $membership->tenant_id)
            ->where('status', 'ACTIVE')
            ->sharedLock()
            ->first();

        $this->assertSame(
            (string) $room->getKey(),
            (string) $lockedRoom->id,
        );
        $this->assertSame(
            (string) $building->getKey(),
            (string) $lockedBuilding->id,
        );
        $this->assertSame(
            (string) $dormitory->getKey(),
            (string) $lockedDormitory->id,
        );
        $this->assertSame(
            (string) $membership->getKey(),
            (string) $lockedMembership->id,
        );
    }

    private function lockPlannedPlacement(
        Connection $connection,
        ResidentPlacement $placement,
    ): void {
        $lockedPlacement = $connection->table('resident_placements')
            ->where('id', $placement->getKey())
            ->where('status', PlacementStatus::PLANNED->value)
            ->lockForUpdate()
            ->first();

        $this->assertSame(
            (string) $placement->getKey(),
            (string) $lockedPlacement->id,
        );
    }

    private function lockBed(
        Connection $connection,
        ResidentPlacement $placement,
        Room $room,
        Bed $bed,
    ): void {
        $lockedBed = $connection->table('beds')
            ->where('id', $bed->getKey())
            ->where('room_id', $room->getKey())
            ->where('tenant_id', $placement->tenant_id)
            ->where('is_active', true)
            ->where('is_usable', true)
            ->lockForUpdate()
            ->first();

        $this->assertSame(
            (string) $bed->getKey(),
            (string) $lockedBed->id,
        );
    }

    private function lockLocker(
        Connection $connection,
        ResidentPlacement $placement,
        Room $room,
        Locker $locker,
    ): void {
        $lockedLocker = $connection->table('lockers')
            ->where('id', $locker->getKey())
            ->where('room_id', $room->getKey())
            ->where('tenant_id', $placement->tenant_id)
            ->where('is_active', true)
            ->where('is_usable', true)
            ->lockForUpdate()
            ->first();

        $this->assertSame(
            (string) $locker->getKey(),
            (string) $lockedLocker->id,
        );
    }

    private function findActivePlacementForMembershipForUpdate(
        Connection $connection,
        Membership $membership,
    ): ?object {
        return $connection->table('resident_placements')
            ->where('tenant_id', $membership->tenant_id)
            ->where('membership_id', $membership->getKey())
            ->where('status', PlacementStatus::ACTIVE->value)
            ->lockForUpdate()
            ->first();
    }

    private function findActivePlacementForBedForUpdate(
        Connection $connection,
        Membership $membership,
        Bed $bed,
    ): ?object {
        return $connection->table('resident_placements')
            ->where('tenant_id', $membership->tenant_id)
            ->where('bed_id', $bed->getKey())
            ->where('status', PlacementStatus::ACTIVE->value)
            ->lockForUpdate()
            ->first();
    }

    private function findActivePlacementForLockerForUpdate(
        Connection $connection,
        Membership $membership,
        Locker $locker,
    ): ?object {
        return $connection->table('resident_placements')
            ->where('tenant_id', $membership->tenant_id)
            ->where('locker_id', $locker->getKey())
            ->where('status', PlacementStatus::ACTIVE->value)
            ->lockForUpdate()
            ->first();
    }

    private function activatePlacement(
        Connection $connection,
        ResidentPlacement $placement,
        Bed $bed,
        Locker $locker,
    ): int {
        return $connection->table('resident_placements')
            ->where('id', $placement->getKey())
            ->update([
                'bed_id' => (string) $bed->getKey(),
                'locker_id' => (string) $locker->getKey(),
                'status' => PlacementStatus::ACTIVE->value,
                'checked_in_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{
     *     0: Membership,
     *     1: Membership,
     *     2: Building,
     *     3: Dormitory,
     *     4: Room,
     *     5: Bed,
     *     6: Bed,
     *     7: Locker,
     *     8: Locker,
     *     9: ResidentPlacement,
     *     10: ResidentPlacement
     * }
     */
    private function createScenario(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Bed And Locker Race Tenant',
            'subdomain' => 'bed-and-locker-race-tenant',
            'is_active' => true,
        ]);

        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);

        $organization = Organization::query()->create([
            'name' => 'Bed And Locker Race Organization',
            'is_active' => true,
        ]);

        $dormitory = Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Bed And Locker Race Dormitory',
            'is_active' => true,
        ]);

        $building = Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => 'Bed And Locker Race Building',
            'is_active' => true,
        ]);

        $room = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => 'Bed And Locker Race Room',
            'capacity_basis' => RoomCapacityBasis::BED_AND_LOCKER,
            'is_active' => true,
        ]);

        $bedA = Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'BED-A',
            'is_usable' => true,
            'is_active' => true,
        ]);
        $bedB = Bed::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'BED-B',
            'is_usable' => true,
            'is_active' => true,
        ]);
        $lockerA = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'LOCKER-A',
            'is_usable' => true,
            'is_active' => true,
        ]);
        $lockerB = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'LOCKER-B',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $membershipA = $this->createActiveMembership(
            $tenant,
            'Bed And Locker Resident A',
        );
        $membershipB = $this->createActiveMembership(
            $tenant,
            'Bed And Locker Resident B',
        );

        $placementA = ResidentPlacement::query()->create([
            'membership_id' => (string) $membershipA->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(10),
        ]);

        $placementB = ResidentPlacement::query()->create([
            'membership_id' => (string) $membershipB->getKey(),
            'room_id' => (string) $room->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(5),
        ]);

        return [
            $membershipA,
            $membershipB,
            $building,
            $dormitory,
            $room,
            $bedA,
            $bedB,
            $lockerA,
            $lockerB,
            $placementA,
            $placementB,
        ];
    }

    private function createActiveMembership(
        Tenant $tenant,
        string $name,
    ): Membership {
        $person = PersonModel::query()->create([
            'name' => $name,
            'status' => 'ACTIVE',
        ]);

        return Membership::query()->create([
            'person_id' => (string) $person->getKey(),
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);
    }

    private function makeIndependentConnection(
        string $name,
    ): Connection {
        $defaultConnection = (string) config('database.default');
        $configuration = config(
            sprintf('database.connections.%s', $defaultConnection),
        );

        if (! is_array($configuration)) {
            $this->fail(sprintf(
                'Database configuration [%s] is unavailable.',
                $defaultConnection,
            ));
        }

        config([
            sprintf('database.connections.%s', $name) => $configuration,
        ]);

        DB::purge($name);

        return DB::connection($name);
    }

    private function rollBackAndPurge(
        string $name,
        Connection $connection,
    ): void {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        DB::disconnect($name);
        DB::purge($name);
    }
}
