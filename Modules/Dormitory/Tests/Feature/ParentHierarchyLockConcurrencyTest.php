<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;
use Modules\Dormitory\Models\Building;
use Modules\Dormitory\Models\Dormitory;
use Modules\Dormitory\Models\Room;
use Tests\TestCase;

final class ParentHierarchyLockConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const SESSION_A = 'dormitory_parent_lock_session_a';

    private const SESSION_B = 'dormitory_parent_lock_session_b';

    public function test_different_room_locks_can_share_parent_hierarchy_locks(): void
    {
        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Dormitory concurrency contracts require PostgreSQL row-lock semantics.',
        );

        [$dormitory, $building, $roomA, $roomB] = $this->createFacility();

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
                'The lock contract must use two independent PostgreSQL sessions.',
            );

            $sessionA->beginTransaction();

            $lockedRoomA = $sessionA->table('rooms')
                ->where('id', $roomA->getKey())
                ->lockForUpdate()
                ->first();

            $lockedBuildingA = $sessionA->table('buildings')
                ->where('id', $building->getKey())
                ->sharedLock()
                ->first();

            $lockedDormitoryA = $sessionA->table('dormitories')
                ->where('id', $dormitory->getKey())
                ->sharedLock()
                ->first();

            $this->assertSame(
                (string) $roomA->getKey(),
                (string) $lockedRoomA->id,
            );
            $this->assertSame(
                (string) $building->getKey(),
                (string) $lockedBuildingA->id,
            );
            $this->assertSame(
                (string) $dormitory->getKey(),
                (string) $lockedDormitoryA->id,
            );

            $sessionB->beginTransaction();
            $sessionB->statement(
                "SET LOCAL lock_timeout = '250ms'",
            );

            $lockedRoomB = $sessionB->table('rooms')
                ->where('id', $roomB->getKey())
                ->lockForUpdate()
                ->first();

            $lockedBuildingB = $sessionB->table('buildings')
                ->where('id', $building->getKey())
                ->sharedLock()
                ->first();

            $lockedDormitoryB = $sessionB->table('dormitories')
                ->where('id', $dormitory->getKey())
                ->sharedLock()
                ->first();

            $this->assertSame(
                (string) $roomB->getKey(),
                (string) $lockedRoomB->id,
            );
            $this->assertSame(
                (string) $building->getKey(),
                (string) $lockedBuildingB->id,
            );
            $this->assertSame(
                (string) $dormitory->getKey(),
                (string) $lockedDormitoryB->id,
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

    /**
     * @return array{0: Dormitory, 1: Building, 2: Room, 3: Room}
     */
    private function createFacility(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Parent Lock Concurrency Tenant',
            'subdomain' => 'parent-lock-concurrency-tenant',
            'is_active' => true,
        ]);

        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);

        $organization = Organization::query()->create([
            'name' => 'Parent Lock Concurrency Organization',
            'is_active' => true,
        ]);

        $dormitory = Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Parent Lock Concurrency Dormitory',
            'is_active' => true,
        ]);

        $building = Building::query()->create([
            'dormitory_id' => (string) $dormitory->getKey(),
            'name' => 'Parent Lock Concurrency Building',
            'is_active' => true,
        ]);

        $roomA = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => 'Parent Lock Room A',
            'capacity_basis' => RoomCapacityBasis::BED,
            'is_active' => true,
        ]);

        $roomB = Room::query()->create([
            'building_id' => (string) $building->getKey(),
            'name' => 'Parent Lock Room B',
            'capacity_basis' => RoomCapacityBasis::BED,
            'is_active' => true,
        ]);

        return [$dormitory, $building, $roomA, $roomB];
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
