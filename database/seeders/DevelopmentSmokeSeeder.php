<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;

final class DevelopmentSmokeSeeder extends Seeder
{
    public const PERSON_ID =
    '019d1d0a-0000-7000-8000-000000000001';

    public const USER_ID =
    '019d1d0a-0000-7000-8000-000000000002';

    public const TENANT_ID =
    '019d1d0a-0000-7000-8000-000000000003';

    public const MEMBERSHIP_ID =
    '019d1d0a-0000-7000-8000-000000000004';

    public function run(): void
    {
        $this->assertSafeEnvironment();
        $this->assertFixtureIdentifiers();

        $personName =
            $this->requiredConfigString(
                'development-smoke.tenant_user.name',
            );

        $email =
            strtolower(
                $this->requiredConfigString(
                    'development-smoke.tenant_user.email',
                ),
            );

        $password =
            $this->requiredConfigString(
                'development-smoke.tenant_user.password',
            );

        $tenantName =
            $this->requiredConfigString(
                'development-smoke.tenant.name',
            );

        $tenantSubdomain =
            strtolower(
                $this->requiredConfigString(
                    'development-smoke.tenant.subdomain',
                ),
            );

        $this->assertValidEmail(
            $email,
        );

        $this->assertValidPassword(
            $password,
        );

        $this->assertValidTenantSubdomain(
            $tenantSubdomain,
        );

        DB::transaction(
            function () use (
                $personName,
                $email,
                $password,
                $tenantName,
                $tenantSubdomain,
            ): void {
                $this->assertNoFixtureCollisions(
                    email: $email,
                    tenantSubdomain: $tenantSubdomain,
                );

                $now =
                    now();

                DB::table(
                    'persons',
                )->updateOrInsert(
                    [
                        'id' =>
                        self::PERSON_ID,
                    ],
                    [
                        'name' =>
                        $personName,

                        'given_name' =>
                        'EduCore',

                        'middle_name' =>
                        'Development',

                        'family_name' =>
                        'User',

                        'status' =>
                        'ACTIVE',

                        'updated_at' =>
                        $now,

                        'created_at' =>
                        $now,
                    ],
                );

                DB::table(
                    'users',
                )->updateOrInsert(
                    [
                        'id' =>
                        self::USER_ID,
                    ],
                    [
                        'person_id' =>
                        self::PERSON_ID,

                        'email' =>
                        $email,

                        'email_verified_at' =>
                        $now,

                        'password' =>
                        Hash::make(
                            $password,
                        ),

                        'status' =>
                        'ACTIVE',

                        /*
                         * This fixture represents a normal Tenant
                         * account. It must never receive global
                         * superadmin authority merely for smoke tests.
                         */
                        'is_superadmin' =>
                        false,

                        'updated_at' =>
                        $now,

                        'created_at' =>
                        $now,
                    ],
                );

                DB::table(
                    'tenants',
                )->updateOrInsert(
                    [
                        'id' =>
                        self::TENANT_ID,
                    ],
                    [
                        'name' =>
                        $tenantName,

                        'subdomain' =>
                        $tenantSubdomain,

                        'domain' =>
                        null,

                        'is_active' =>
                        true,

                        'settings' =>
                        null,

                        'updated_at' =>
                        $now,

                        'created_at' =>
                        $now,
                    ],
                );

                DB::table(
                    'memberships',
                )->updateOrInsert(
                    [
                        'id' =>
                        self::MEMBERSHIP_ID,
                    ],
                    [
                        'person_id' =>
                        self::PERSON_ID,

                        'tenant_id' =>
                        self::TENANT_ID,

                        'status' =>
                        'ACTIVE',

                        'updated_at' =>
                        $now,

                        'created_at' =>
                        $now,
                    ],
                );
            },
        );

        $this->command?->info(
            sprintf(
                'Development Tenant smoke fixture is ready. Tenant UUID: %s',
                self::TENANT_ID,
            ),
        );
    }

    private function assertSafeEnvironment(): void
    {
        if (
            ! app()->environment(
                'local',
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Development smoke seeding refused outside the local environment; current environment: %s.',
                    app()->environment(),
                ),
            );
        }

        if (
            config(
                'development-smoke.enabled',
            ) !== true
        ) {
            throw new RuntimeException(
                'Development smoke seeding is disabled. Set DEVELOPMENT_SMOKE_SEED_ENABLED=true to opt in.',
            );
        }

        $connection =
            config(
                'database.default',
            );

        if (
            ! is_string(
                $connection,
            )
            || trim(
                $connection,
            ) === ''
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: invalid database connection.',
            );
        }

        $currentDatabase =
            config(
                sprintf(
                    'database.connections.%s.database',
                    $connection,
                ),
            );

        if (
            ! is_string(
                $currentDatabase,
            )
            || trim(
                $currentDatabase,
            ) === ''
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: active database name is unavailable.',
            );
        }

        $expectedDatabase =
            $this->requiredConfigString(
                'development-smoke.database',
            );

        if (
            trim(
                $currentDatabase,
            ) !== $expectedDatabase
        ) {
            throw new RuntimeException(
                sprintf(
                    'Development smoke seeding refused: expected database %s but active database is %s.',
                    $expectedDatabase,
                    $currentDatabase,
                ),
            );
        }

        if (
            $currentDatabase
            === 'educore_e2e'
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: the E2E database is reserved for deterministic browser automation.',
            );
        }
    }

    private function assertFixtureIdentifiers(): void
    {
        foreach (
            [
                self::PERSON_ID,
                self::USER_ID,
                self::TENANT_ID,
                self::MEMBERSHIP_ID,
            ]
            as $identifier
        ) {
            if (
                ! UuidV7::validate(
                    $identifier,
                )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Development smoke fixture contains an invalid UUIDv7 identifier: %s.',
                        $identifier,
                    ),
                );
            }
        }
    }

    private function assertNoFixtureCollisions(
        string $email,
        string $tenantSubdomain,
    ): void {
        $userForEmail =
            DB::table(
                'users',
            )
            ->whereRaw(
                'LOWER(email) = ?',
                [
                    $email,
                ],
            )
            ->first([
                'id',
                'person_id',
                'is_superadmin',
            ]);

        if (
            $userForEmail !== null
            && (
                (string) $userForEmail->id
                !== self::USER_ID
                || (string) $userForEmail->person_id
                !== self::PERSON_ID
                || (bool) $userForEmail->is_superadmin
                === true
            )
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: configured Tenant user email belongs to another identity.',
            );
        }

        $userForFixtureId =
            DB::table(
                'users',
            )
            ->where(
                'id',
                self::USER_ID,
            )
            ->first([
                'email',
                'person_id',
                'is_superadmin',
            ]);

        if (
            $userForFixtureId !== null
            && (
                strtolower(
                    trim(
                        (string) $userForFixtureId->email,
                    ),
                ) !== $email
                || (string) $userForFixtureId->person_id
                !== self::PERSON_ID
                || (bool) $userForFixtureId->is_superadmin
                === true
            )
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: fixture User ID is already owned by another identity.',
            );
        }

        $otherUserForPerson =
            DB::table(
                'users',
            )
            ->where(
                'person_id',
                self::PERSON_ID,
            )
            ->where(
                'id',
                '<>',
                self::USER_ID,
            )
            ->exists();

        if (
            $otherUserForPerson
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: fixture Person ID is already attached to another User.',
            );
        }

        $tenantForSubdomain =
            DB::table(
                'tenants',
            )
            ->where(
                'subdomain',
                $tenantSubdomain,
            )
            ->first([
                'id',
            ]);

        if (
            $tenantForSubdomain !== null
            && (string) $tenantForSubdomain->id
            !== self::TENANT_ID
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: configured Tenant subdomain belongs to another Tenant.',
            );
        }

        $tenantForFixtureId =
            DB::table(
                'tenants',
            )
            ->where(
                'id',
                self::TENANT_ID,
            )
            ->first([
                'subdomain',
            ]);

        if (
            $tenantForFixtureId !== null
            && strtolower(
                trim(
                    (string) $tenantForFixtureId->subdomain,
                ),
            ) !== $tenantSubdomain
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: fixture Tenant ID is already owned by another Tenant.',
            );
        }

        $membershipForFixtureId =
            DB::table(
                'memberships',
            )
            ->where(
                'id',
                self::MEMBERSHIP_ID,
            )
            ->first([
                'person_id',
                'tenant_id',
            ]);

        if (
            $membershipForFixtureId !== null
            && (
                (string) $membershipForFixtureId->person_id
                !== self::PERSON_ID
                || (string) $membershipForFixtureId->tenant_id
                !== self::TENANT_ID
            )
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: fixture Membership ID belongs to another Person/Tenant pair.',
            );
        }

        $membershipForPair =
            DB::table(
                'memberships',
            )
            ->where(
                'person_id',
                self::PERSON_ID,
            )
            ->where(
                'tenant_id',
                self::TENANT_ID,
            )
            ->first([
                'id',
            ]);

        if (
            $membershipForPair !== null
            && (string) $membershipForPair->id
            !== self::MEMBERSHIP_ID
        ) {
            throw new RuntimeException(
                'Development smoke seeding refused: fixture Person/Tenant pair already has another Membership.',
            );
        }
    }

    private function assertValidEmail(
        string $email,
    ): void {
        if (
            filter_var(
                $email,
                FILTER_VALIDATE_EMAIL,
            ) === false
        ) {
            throw new RuntimeException(
                'Development smoke Tenant user email must be a valid email address.',
            );
        }
    }

    private function assertValidPassword(
        string $password,
    ): void {
        if (
            mb_strlen(
                $password,
            ) < 12
        ) {
            throw new RuntimeException(
                'Development smoke Tenant user password must contain at least 12 characters.',
            );
        }
    }

    private function assertValidTenantSubdomain(
        string $tenantSubdomain,
    ): void {
        if (
            preg_match(
                '/^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/',
                $tenantSubdomain,
            ) !== 1
        ) {
            throw new RuntimeException(
                'Development smoke Tenant subdomain must contain only lowercase letters, digits, or interior hyphens and be at most 50 characters.',
            );
        }
    }

    private function requiredConfigString(
        string $key,
    ): string {
        $value =
            config(
                $key,
            );

        if (
            ! is_string(
                $value,
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Development smoke configuration %s is required.',
                    $key,
                ),
            );
        }

        $value =
            trim(
                $value,
            );

        if (
            $value === ''
        ) {
            throw new RuntimeException(
                sprintf(
                    'Development smoke configuration %s must not be empty.',
                    $key,
                ),
            );
        }

        return $value;
    }
}
