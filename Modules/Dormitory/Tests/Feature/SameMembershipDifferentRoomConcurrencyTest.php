<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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
use Modules\Dormitory\Models\ResidentPlacement;
use Modules\Dormitory\Models\Room;
use Tests\TestCase;

final class SameMembershipDifferentRoomConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const SESSION_A = 'dormitory_same_membership_session_a';

    private const SESSION_B = 'dormitory_same_membership_session_b';

    public function test_same_membership_different_room_race_allows_only_one_active_placement(): void
    {
        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Dormitory concurrency contracts require PostgreSQL row-lock semantics.',
        );

        [
            $membership,
            $building,
            $dormitory,
            $roomA,
            $roomB,
            $bedA,
            $bedB,
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
                'The race proof must use two independent PostgreSQL sessions.',
            );

            $sessionA->beginTransaction();
            $sessionB->beginTransaction();

            $this->lockHierarchyAndMembership(
                $sessionA,
                $membership,
                $building,
                $dormitory,
                $roomA,
            );
            $this->lockHierarchyAndMembership(
                $sessionB,
                $membership,
                $building,
                $dormitory,
                $roomB,
            );

            $this->assertNull(
                $this->findActivePlacementForUpdate(
                    $sessionA,
                    $membership,
                ),
            );
            $this->assertNull(
                $this->findActivePlacementForUpdate(
                    $sessionB,
                    $membership,
                ),
            );

            $this->lockPlannedPlacementAndBed(
                $sessionA,
                $placementA,
                $roomA,
                $bedA,
            );
            $this->lockPlannedPlacementAndBed(
                $sessionB,
                $placementB,
                $roomB,
                $bedB,
            );

            $this->assertSame(
                1,
                $this->activatePlacement(
                    $sessionA,
                    $placementA,
                    $bedA,
                ),
            );

            $sessionB->statement(
                "SET LOCAL lock_timeout = '250ms'",
            );

            try {
                $this->activatePlacement(
                    $sessionB,
                    $placementB,
                    $bedB,
                );

                $this->fail(
                    'The competing activation must wait for the uncommitted winner.',
                );
            } catch (QueryException $exception) {
                $this->assertSame(
                    '55P03',
                    $exception->errorInfo[0] ?? null,
                    'The competing activation must hit the bounded PostgreSQL lock timeout.',
                );
            } finally {
                if ($sessionB->transactionLevel() > 0) {
                    $sessionB->rollBack();
                }
            }

            $sessionA->commit();

            $sessionB->beginTransaction();

            try {
                $this->activatePlacement(
                    $sessionB,
                    $placementB,
                    $bedB,
                );

                $this->fail(
                    'The committed active membership must reject the competing placement.',
                );
            } catch (UniqueConstraintViolationException $exception) {
                $this->assertSame(
                    '23505',
                    $exception->errorInfo[0] ?? null,
                );
                $this->assertStringContainsString(
                    'uq_resident_placements_active_membership',
                    (string) ($exception->errorInfo[2] ?? ''),
                );
            } finally {
                if ($sessionB->transactionLevel() > 0) {
                    $sessionB->rollBack();
                }
            }

            $activePlacements = DB::table('resident_placements')
                ->where('tenant_id', $membership->tenant_id)
                ->where('membership_id', $membership->getKey())
                ->where('status', PlacementStatus::ACTIVE->value)
                ->get();

            $this->assertCount(1, $activePlacements);
            $this->assertSame(
                (string) $placementA->getKey(),
                (string) $activePlacements->first()->id,
            );

            $losingPlacement = DB::table('resident_placements')
                ->where('id', $placementB->getKey())
                ->first();

            $this->assertNotNull($losingPlacement);
            $this->assertSame(
                PlacementStatus::PLANNED->value,
                $losingPlacement->status,
            );
            $this->assertNull($losingPlacement->bed_id);
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

    private function lockHierarchyAndMembership(
        Connection $connection,
        Membership $membership,
        Building $building,
        Dormitory $dormitory,
        Room $room,
    ): void {
        $lockedRoom = $connection->table('rooms')
            ->where('id', $room->getKey())
            ->lockForUpdate()
            ->first();
        $lockedBuilding = $connection->table('buildings')
            ->where('id', $building->getKey())
            ->sharedLock()
            ->first();
        $lockedDormitory = $connection->table('dormitories')
            ->where('id', $dormitory->getKey())
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

    private function lockPlannedPlacementAndBed(
        Connection $connection,
        ResidentPlacement $placement,
        Room $room,
        Bed $bed,
    ): void {
        $lockedPlacement = $connection->table('resident_placements')
            ->where('id', $placement->getKey())
            ->where('status', PlacementStatus::PLANNED->value)
            ->lockForUpdate()
            ->first();
        $lockedBed = $connection->table('beds')
            ->where('id', $bed->getKey())
            ->where('room_id', $room->getKey())
            ->where('is_active', true)
            ->where('is_usable', true)
            ->lockForUpdate()
            ->first();

        $this->assertSame(
            (string) $placement->getKey(),
            (string) $lockedPlacement->id,
        );
        $this->assertSame(
            (string) $bed->getKey(),
            (string) $lockedBed->id,
        );
    }

    private function findActivePlacementForUpdate(
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

    private function activatePlacement(
        Connection $connection,
        ResidentPlacement $placement,
        Bed $bed,
    ): int {
        return $connection->table('resident_placements')
            ->where('id', $placement->getKey())
            ->update([
                'bed_id' => (string) $bed->getKey(),
                'status' => PlacementStatus::ACTIVE->value,
                'checked_in_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{
     *     0: Membership,
     *     1: Building,
     *     2: Dormitory,
     *     3: Room,
     *     4: Room,
     *     5: Bed,
     *     6: Bed,
     *     7: ResidentPlacement,
     *     8: ResidentPlacement
     * }
     */
    private function createScenario(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Same Membership Race Tenant',
            'subdomain' => 'same-membership-race-tenant',
            'is_active' => true,
        ]);

        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);

        $organization = Organization::query()->create([
            'name' => 'Same Membership Race Organization',
            'is_active' => true,
        ]);

        $dormitory = Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Same Membership Race Dormitory',
            'is_active' => true,
        ]);

        $building = Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => 'Same Membership Race Building',
            'is_active' => true,
        ]);

        $roomA = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => 'Same Membership Room A',
            'capacity_basis' => RoomCapacityBasis::BED,
            'is_active' => true,
        ]);

        $roomB = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => 'Same Membership Room B',
            'capacity_basis' => RoomCapacityBasis::BED,
            'is_active' => true,
        ]);

        $bedA = Bed::query()->create([
            'room_id' => (string) $roomA->getKey(),
            'code' => 'SAME-MEMBER-A',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $bedB = Bed::query()->create([
            'room_id' => (string) $roomB->getKey(),
            'code' => 'SAME-MEMBER-B',
            'is_usable' => true,
            'is_active' => true,
        ]);

        $person = PersonModel::query()->create([
            'name' => 'Same Membership Resident',
            'status' => 'ACTIVE',
        ]);

        $membership = Membership::query()->create([
            'person_id' => (string) $person->getKey(),
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $placementA = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $roomA->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(10),
        ]);

        $placementB = ResidentPlacement::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'room_id' => (string) $roomB->getKey(),
            'resident_category' => ResidentCategory::REGULAR_RESIDENT,
            'status' => PlacementStatus::PLANNED,
            'planned_at' => now()->subMinutes(5),
        ]);

        return [
            $membership,
            $building,
            $dormitory,
            $roomA,
            $roomB,
            $bedA,
            $bedB,
            $placementA,
            $placementB,
        ];
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
