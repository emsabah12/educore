<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;

final class E2EBrowserAuthenticationSeeder extends Seeder
{
    public const PERSON_ID =
        '019c8f4a-7b10-7000-8000-000000000001';

    public const USER_ID =
        '019c8f4a-7b10-7000-8000-000000000002';

    public const TENANT_ID =
        '019c8f4a-7b10-7000-8000-000000000003';

    public const MEMBERSHIP_ID =
        '019c8f4a-7b10-7000-8000-000000000004';

    public const SECOND_TENANT_ID =
        '019c8f4a-7b10-7000-8000-000000000005';

    public const SECOND_MEMBERSHIP_ID =
        '019c8f4a-7b10-7000-8000-000000000006';

    public const ORGANIZATION_ID =
        '019c8f4a-7b10-7000-8000-000000000007';

    public const ORGANIZATIONAL_ASSIGNMENT_ID =
        '019c8f4a-7b10-7000-8000-000000000008';

    public const ORGANIZATION_NAME =
        'EduCore Browser E2E Organization';

    public const ORGANIZATION_CODE =
        'E2E-ORG';

    public const EMAIL =
        'browser-e2e@educore.test';

    public const PASSWORD =
        'E2eOnly-Secret123!';

    public const TENANT_SUBDOMAIN =
        'browser-e2e';

    public const SECOND_TENANT_SUBDOMAIN =
        'browser-e2e-secondary';

    public function run(): void
    {
        $this->assertSafeEnvironment();
        $this->assertFixtureIdentifiers();

        DB::transaction(
            function (): void {
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
                            'EduCore Browser E2E User',

                        'status' =>
                            'ACTIVE',

                        'created_at' =>
                            $now,

                        'updated_at' =>
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
                            self::EMAIL,

                        'password' =>
                            Hash::make(
                                self::PASSWORD,
                            ),

                        'status' =>
                            'ACTIVE',

                        'is_superadmin' =>
                            false,

                        'created_at' =>
                            $now,

                        'updated_at' =>
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
                            'EduCore Browser E2E Tenant',

                        'subdomain' =>
                            self::TENANT_SUBDOMAIN,

                        'is_active' =>
                            true,

                        'created_at' =>
                            $now,

                        'updated_at' =>
                            $now,
                    ],
                );

                DB::table(
                    'tenants',
                )->updateOrInsert(
                    [
                        'id' =>
                            self::SECOND_TENANT_ID,
                    ],
                    [
                        'name' =>
                            'EduCore Browser E2E Tenant Secondary',

                        'subdomain' =>
                            self::SECOND_TENANT_SUBDOMAIN,

                        'is_active' =>
                            true,

                        'created_at' =>
                            $now,

                        'updated_at' =>
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

                        'created_at' =>
                            $now,

                        'updated_at' =>
                            $now,
                    ],
                );

                DB::table(
                    'memberships',
                )->updateOrInsert(
                    [
                        'id' =>
                            self::SECOND_MEMBERSHIP_ID,
                    ],
                    [
                        'person_id' =>
                            self::PERSON_ID,

                        'tenant_id' =>
                            self::SECOND_TENANT_ID,

                        'status' =>
                            'ACTIVE',

                        'created_at' =>
                            $now,

                        'updated_at' =>
                            $now,
                    ],
                );

                /*
                 * Membership A owns one deterministic
                 * organizational Workspace inside Tenant A.
                 *
                 * The fixture deliberately starts at
                 * Organization scope. Organization Unit
                 * coverage belongs to a later scenario.
                 */
                DB::table(
                    'organizations',
                )->updateOrInsert(
                    [
                        'id' =>
                            self::ORGANIZATION_ID,
                    ],
                    [
                        'tenant_id' =>
                            self::TENANT_ID,

                        'name' =>
                            self::ORGANIZATION_NAME,

                        'code' =>
                            self::ORGANIZATION_CODE,

                        'is_active' =>
                            true,

                        'deleted_at' =>
                            null,

                        'created_at' =>
                            $now,

                        'updated_at' =>
                            $now,
                    ],
                );

                DB::table(
                    'organizational_assignments',
                )->updateOrInsert(
                    [
                        'id' =>
                            self::ORGANIZATIONAL_ASSIGNMENT_ID,
                    ],
                    [
                        'tenant_id' =>
                            self::TENANT_ID,

                        'membership_id' =>
                            self::MEMBERSHIP_ID,

                        'organization_id' =>
                            self::ORGANIZATION_ID,

                        'organization_unit_id' =>
                            null,

                        'status' =>
                            'ACTIVE',

                        'created_at' =>
                            $now,

                        'updated_at' =>
                            $now,
                    ],
                );
            },
        );
    }

    private function assertSafeEnvironment(): void
    {
        $environment =
            app()->environment();

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
                'E2E fixture refused: invalid database connection.',
            );
        }

        $database =
            config(
                sprintf(
                    'database.connections.%s.database',
                    $connection,
                ),
            );

        if (
            $environment !== 'e2e'
            || $database !== 'educore_e2e'
        ) {
            throw new RuntimeException(
                sprintf(
                    'E2E fixture refused: expected environment e2e and database educore_e2e; received environment %s and database %s.',
                    is_string($environment)
                        ? $environment
                        : get_debug_type(
                            $environment,
                        ),
                    is_string($database)
                        ? $database
                        : get_debug_type(
                            $database,
                        ),
                ),
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
                self::SECOND_TENANT_ID,
                self::SECOND_MEMBERSHIP_ID,
                self::ORGANIZATION_ID,
                self::ORGANIZATIONAL_ASSIGNMENT_ID,
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
                        'E2E fixture contains invalid UUIDv7 identifier: %s.',
                        $identifier,
                    ),
                );
            }
        }
    }
}
