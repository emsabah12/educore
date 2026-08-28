<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class E2EStaleWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Never permit this mutation harness outside the same
         * isolated environment/database boundary owned by the
         * canonical browser E2E fixture.
         */
        E2EBrowserAuthenticationSeeder::assertSafeEnvironment();

        /*
         * Mutate only the exact deterministic organizational
         * assignment owned by Membership A / Tenant A.
         *
         * Every ownership predicate is included so a fixture
         * drift cannot accidentally mutate another assignment.
         */
        $updated =
            DB::table(
                'organizational_assignments',
            )
                ->where(
                    'id',
                    E2EBrowserAuthenticationSeeder::ORGANIZATIONAL_ASSIGNMENT_ID,
                )
                ->where(
                    'tenant_id',
                    E2EBrowserAuthenticationSeeder::TENANT_ID,
                )
                ->where(
                    'membership_id',
                    E2EBrowserAuthenticationSeeder::MEMBERSHIP_ID,
                )
                ->where(
                    'organization_id',
                    E2EBrowserAuthenticationSeeder::ORGANIZATION_ID,
                )
                ->whereNull(
                    'organization_unit_id',
                )
                ->where(
                    'status',
                    'ACTIVE',
                )
                ->update([
                    'status' =>
                        'INACTIVE',

                    'updated_at' =>
                        now(),
                ]);

        if (
            $updated !== 1
        ) {
            throw new RuntimeException(
                sprintf(
                    'Stale Workspace E2E mutation refused: expected exactly one ACTIVE organizational assignment %s owned by Membership %s and Tenant %s; updated %d.',
                    E2EBrowserAuthenticationSeeder::ORGANIZATIONAL_ASSIGNMENT_ID,
                    E2EBrowserAuthenticationSeeder::MEMBERSHIP_ID,
                    E2EBrowserAuthenticationSeeder::TENANT_ID,
                    $updated,
                ),
            );
        }
    }
}
