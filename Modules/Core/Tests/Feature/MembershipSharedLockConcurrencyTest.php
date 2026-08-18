<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\EloquentMembershipRepository;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class MembershipSharedLockConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const SESSION_A = 'core_membership_share_session_a';

    private const SESSION_B = 'core_membership_share_session_b';

    public function test_shared_membership_lookup_blocks_status_update_until_transaction_ends(): void
    {
        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Membership locking contracts require PostgreSQL row-lock semantics.',
        );

        [$tenant, $membership] = $this->createActiveMembership();

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

            $defaultConnection = DB::getDefaultConnection();

            DB::setDefaultConnection(self::SESSION_A);

            try {
                $repository = new EloquentMembershipRepository;

                $lockedMembership = $repository
                    ->findActiveMembershipByIdAndTenantForShare(
                        (string) $membership->getKey(),
                        (string) $tenant->getKey(),
                    );
            } finally {
                DB::setDefaultConnection($defaultConnection);
            }

            $this->assertNotNull($lockedMembership);
            $this->assertSame(
                (string) $membership->getKey(),
                (string) $lockedMembership->getKey(),
            );
            $this->assertSame(
                'ACTIVE',
                $lockedMembership->status,
            );

            $sessionB->beginTransaction();
            $sessionB->statement(
                "SET LOCAL lock_timeout = '250ms'",
            );

            try {
                $sessionB->table('memberships')
                    ->where('id', $membership->getKey())
                    ->update([
                        'status' => 'INACTIVE',
                    ]);

                $this->fail(
                    'Membership status update must wait while the shared lock is held.',
                );
            } catch (QueryException $exception) {
                $this->assertSame(
                    '55P03',
                    $exception->errorInfo[0] ?? null,
                    'PostgreSQL must reject the blocked update with lock_not_available.',
                );
            } finally {
                if ($sessionB->transactionLevel() > 0) {
                    $sessionB->rollBack();
                }
            }

            $this->assertSame(
                'ACTIVE',
                DB::table('memberships')
                    ->where('id', $membership->getKey())
                    ->value('status'),
            );

            $sessionA->rollBack();

            $sessionB->beginTransaction();

            $updated = $sessionB->table('memberships')
                ->where('id', $membership->getKey())
                ->update([
                    'status' => 'INACTIVE',
                ]);

            $sessionB->commit();

            $this->assertSame(1, $updated);
            $this->assertSame(
                'INACTIVE',
                DB::table('memberships')
                    ->where('id', $membership->getKey())
                    ->value('status'),
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
     * @return array{0: Tenant, 1: Membership}
     */
    private function createActiveMembership(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Membership Lock Tenant',
            'subdomain' => 'membership-lock-tenant',
            'is_active' => true,
        ]);

        $person = PersonModel::query()->create([
            'name' => 'Membership Lock Resident',
            'status' => 'ACTIVE',
        ]);

        $membership = Membership::query()->create([
            'person_id' => (string) $person->getKey(),
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        return [$tenant, $membership];
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
