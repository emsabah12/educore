<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DevelopmentSmokeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class DevelopmentSmokeSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_refuses_to_run_outside_local_environment(): void
    {
        config([
            'development-smoke.enabled' =>
            true,

            'development-smoke.database' =>
            $this->currentDatabaseName(),

            'development-smoke.tenant_user.email' =>
            'tenant.dev@educore.test',

            'development-smoke.tenant_user.password' =>
            'DevelopmentOnly!2026',
        ]);

        $this->expectException(
            RuntimeException::class,
        );

        $this->expectExceptionMessage(
            'outside the local environment',
        );

        (new DevelopmentSmokeSeeder())
            ->run();
    }

    public function test_it_requires_explicit_opt_in(): void
    {
        $this->forceLocalEnvironment();

        config([
            'development-smoke.enabled' =>
            false,

            'development-smoke.database' =>
            $this->currentDatabaseName(),

            'development-smoke.tenant_user.email' =>
            'tenant.dev@educore.test',

            'development-smoke.tenant_user.password' =>
            'DevelopmentOnly!2026',
        ]);

        $this->expectException(
            RuntimeException::class,
        );

        $this->expectExceptionMessage(
            'Development smoke seeding is disabled',
        );

        (new DevelopmentSmokeSeeder())
            ->run();
    }

    public function test_it_requires_the_explicit_database_boundary(): void
    {
        $this->forceLocalEnvironment();

        config([
            'development-smoke.enabled' =>
            true,

            'development-smoke.database' =>
            'definitely-not-the-current-database',

            'development-smoke.tenant_user.email' =>
            'tenant.dev@educore.test',

            'development-smoke.tenant_user.password' =>
            'DevelopmentOnly!2026',
        ]);

        $this->expectException(
            RuntimeException::class,
        );

        $this->expectExceptionMessage(
            'expected database',
        );

        (new DevelopmentSmokeSeeder())
            ->run();
    }

    public function test_it_seeds_an_idempotent_canonical_tenant_identity(): void
    {
        $this->forceLocalEnvironment();

        $email =
            'tenant.dev@educore.test';

        $password =
            'DevelopmentOnly!2026';

        config([
            'development-smoke.enabled' =>
            true,

            'development-smoke.database' =>
            $this->currentDatabaseName(),

            'development-smoke.tenant.name' =>
            'EduCore Development School',

            'development-smoke.tenant.subdomain' =>
            'educore-development',

            'development-smoke.tenant_user.name' =>
            'EduCore Development User',

            'development-smoke.tenant_user.email' =>
            $email,

            'development-smoke.tenant_user.password' =>
            $password,
        ]);

        $seeder =
            new DevelopmentSmokeSeeder();

        $seeder->run();
        $seeder->run();

        $this->assertSame(
            1,
            DB::table('persons')
                ->where(
                    'id',
                    DevelopmentSmokeSeeder::PERSON_ID,
                )
                ->count(),
        );

        $this->assertSame(
            1,
            DB::table('users')
                ->where(
                    'id',
                    DevelopmentSmokeSeeder::USER_ID,
                )
                ->count(),
        );

        $this->assertSame(
            1,
            DB::table('tenants')
                ->where(
                    'id',
                    DevelopmentSmokeSeeder::TENANT_ID,
                )
                ->count(),
        );

        $this->assertSame(
            1,
            DB::table('memberships')
                ->where(
                    'id',
                    DevelopmentSmokeSeeder::MEMBERSHIP_ID,
                )
                ->count(),
        );

        $user =
            DB::table(
                'users',
            )
            ->where(
                'id',
                DevelopmentSmokeSeeder::USER_ID,
            )
            ->first();

        $this->assertNotNull(
            $user,
        );

        $this->assertSame(
            DevelopmentSmokeSeeder::PERSON_ID,
            (string) $user->person_id,
        );

        $this->assertSame(
            $email,
            (string) $user->email,
        );

        $this->assertSame(
            'ACTIVE',
            (string) $user->status,
        );

        $this->assertFalse(
            (bool) $user->is_superadmin,
        );

        $this->assertTrue(
            Hash::check(
                $password,
                (string) $user->password,
            ),
        );

        $tenant =
            DB::table(
                'tenants',
            )
            ->where(
                'id',
                DevelopmentSmokeSeeder::TENANT_ID,
            )
            ->first();

        $this->assertNotNull(
            $tenant,
        );

        $this->assertSame(
            'EduCore Development School',
            (string) $tenant->name,
        );

        $this->assertSame(
            'educore-development',
            (string) $tenant->subdomain,
        );

        $this->assertTrue(
            (bool) $tenant->is_active,
        );

        $membership =
            DB::table(
                'memberships',
            )
            ->where(
                'id',
                DevelopmentSmokeSeeder::MEMBERSHIP_ID,
            )
            ->first();

        $this->assertNotNull(
            $membership,
        );

        $this->assertSame(
            DevelopmentSmokeSeeder::PERSON_ID,
            (string) $membership->person_id,
        );

        $this->assertSame(
            DevelopmentSmokeSeeder::TENANT_ID,
            (string) $membership->tenant_id,
        );

        $this->assertSame(
            'ACTIVE',
            (string) $membership->status,
        );
    }

    private function forceLocalEnvironment(): void
    {
        app()->detectEnvironment(
            static fn(): string =>
            'local',
        );
    }

    private function currentDatabaseName(): string
    {
        $connection =
            config(
                'database.default',
            );

        $this->assertIsString(
            $connection,
        );

        $database =
            config(
                sprintf(
                    'database.connections.%s.database',
                    $connection,
                ),
            );

        $this->assertIsString(
            $database,
        );

        $database =
            trim(
                $database,
            );

        $this->assertNotSame(
            '',
            $database,
        );

        return $database;
    }
}
