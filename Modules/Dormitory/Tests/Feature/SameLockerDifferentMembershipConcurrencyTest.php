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
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\Locker;
use Modules\Dormitory\Models\ResidentPlacement;
use Modules\Dormitory\Models\Room;
use Tests\TestCase;

final class SameLockerDifferentMembershipConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const SESSION_A = 'dormitory_same_locker_session_a';

    private const SESSION_B = 'dormitory_same_locker_session_b';

    public function test_same_locker_different_memberships_serialize_on_room_lock_and_revalidate_occupancy(): void
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
            $locker,
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
                $locker,
                $placementA,
            );

            $this->assertSame(
                1,
                $this->activatePlacement(
                    $sessionA,
                    $placementA,
                    $locker,
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

            $this->lockHierarchyAndMembership(
                $sessionB,
                $membershipB,
                $building,
                $dormitory,
                $room,
            );

            $this->assertNull(
                $this->findActivePlacementForMembershipForUpdate(
                    $sessionB,
                    $membershipB,
                ),
            );

            $this->lockPlannedPlacementAndLocker(
                $sessionB,
                $placementB,
                $room,
                $locker,
            );

            $activeLockerPlacement = $this->findActivePlacementForLockerForUpdate(
                $sessionB,
                $membershipB,
                $locker,
            );

            $this->assertNotNull($activeLockerPlacement);
            $this->assertSame(
                (string) $placementA->getKey(),
                (string) $activeLockerPlacement->id,
            );

            $sessionB->rollBack();

            $activeLockerPlacements = DB::table('resident_placements')
                ->where('tenant_id', $membershipA->tenant_id)
                ->where('locker_id', $locker->getKey())
                ->where('status', PlacementStatus::ACTIVE->value)
                ->get();

            $this->assertCount(1, $activeLockerPlacements);
            $this->assertSame(
                (string) $placementA->getKey(),
                (string) $activeLockerPlacements->first()->id,
            );

            $losingPlacement = DB::table('resident_placements')
                ->where('id', $placementB->getKey())
                ->first();

            $this->assertNotNull($losingPlacement);
            $this->assertSame(
                PlacementStatus::PLANNED->value,
                $losingPlacement->status,
            );
            $this->assertNull($losingPlacement->locker_id);
            $this->assertNull($losingPlacement->checked_in_at);
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

        $this->lockPlannedPlacementAndLocker(
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

    private function lockPlannedPlacementAndLocker(
        Connection $connection,
        ResidentPlacement $placement,
        Room $room,
        Locker $locker,
    ): void {
        $lockedPlacement = $connection->table('resident_placements')
            ->where('id', $placement->getKey())
            ->where('status', PlacementStatus::PLANNED->value)
            ->lockForUpdate()
            ->first();
        $lockedLocker = $connection->table('lockers')
            ->where('id', $locker->getKey())
            ->where('room_id', $room->getKey())
            ->where('tenant_id', $placement->tenant_id)
            ->where('is_active', true)
            ->where('is_usable', true)
            ->lockForUpdate()
            ->first();

        $this->assertSame(
            (string) $placement->getKey(),
            (string) $lockedPlacement->id,
        );
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
        Locker $locker,
    ): int {
        return $connection->table('resident_placements')
            ->where('id', $placement->getKey())
            ->update([
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
     *     5: Locker,
     *     6: ResidentPlacement,
     *     7: ResidentPlacement
     * }
     */
    private function createScenario(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Same Locker Race Tenant',
            'subdomain' => 'same-locker-race-tenant',
            'is_active' => true,
        ]);

        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);

        $organization = Organization::query()->create([
            'name' => 'Same Locker Race Organization',
            'is_active' => true,
        ]);

        $dormitory = Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Same Locker Race Dormitory',
            'is_active' => true,
        ]);

        $building = Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => 'Same Locker Race Building',
            'is_active' => true,
        ]);

        $room = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => 'Same Locker Race Room',
            'capacity_basis' => RoomCapacityBasis::LOCKER,
            'is_active' => true,
        ]);

        $locker = Locker::query()->create([
            'room_id' => (string) $room->getKey(),
            'code' => 'SAME-LOCKER-X',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $membershipA = $this->createActiveMembership(
            $tenant,
            'Same Locker Resident A',
        );
        $membershipB = $this->createActiveMembership(
            $tenant,
            'Same Locker Resident B',
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
            $locker,
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
