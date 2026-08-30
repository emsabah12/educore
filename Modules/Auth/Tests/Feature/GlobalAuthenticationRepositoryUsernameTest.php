<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Repositories\AuthenticationRepository;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class GlobalAuthenticationRepositoryUsernameTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_be_resolved_by_username_without_membership(): void
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();

        $username = sprintf(
            'global-user-%s',
            Str::lower(Str::random(8)),
        );

        $this->insertPerson(
            personId: $personId,
            name: 'Global Username User',
        );

        $this->insertUser(
            userId: $userId,
            personId: $personId,
            email: $this->uniqueEmail('global-username'),
            username: $username,
            status: 'ACTIVE',
        );

        $repository = new AuthenticationRepository();

        $identity = $repository->findActiveByLoginIdentifier(
            $username,
        );

        $this->assertNotNull(
            $identity,
            'An active global User must be resolvable by username without Membership context.',
        );

        $this->assertSame(
            $userId,
            $identity['user_id'] ?? null,
        );

        $this->assertSame(
            $username,
            $identity['username'] ?? null,
            'Global authentication projection must expose the canonical username.',
        );
    }

    public function test_username_lookup_normalizes_trim_and_case(): void
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();

        $username = sprintf(
            'normalized-%s',
            Str::lower(Str::random(8)),
        );

        $this->insertPerson(
            personId: $personId,
            name: 'Normalized Username User',
        );

        $this->insertUser(
            userId: $userId,
            personId: $personId,
            email: $this->uniqueEmail('normalized-username'),
            username: $username,
            status: 'ACTIVE',
        );

        $repository = new AuthenticationRepository();

        $identity = $repository->findActiveByLoginIdentifier(
            sprintf(
                '  %s  ',
                strtoupper($username),
            ),
        );

        $this->assertNotNull(
            $identity,
            'Username authentication lookup must normalize trim and case before querying.',
        );

        $this->assertSame(
            $userId,
            $identity['user_id'] ?? null,
        );
    }

    public function test_inactive_tenant_does_not_invalidate_username_identity_lookup(): void
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $username = sprintf(
            'tenant-independent-%s',
            Str::lower(Str::random(8)),
        );

        $this->insertPerson(
            personId: $personId,
            name: 'Tenant Independent Username User',
        );

        $this->insertUser(
            userId: $userId,
            personId: $personId,
            email: $this->uniqueEmail('tenant-independent-username'),
            username: $username,
            status: 'ACTIVE',
        );

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Inactive Username Authentication Tenant',
            'subdomain' => sprintf(
                'username-auth-%s',
                Str::lower(Str::random(8)),
            ),
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $repository = new AuthenticationRepository();

        $identity = $repository->findActiveByLoginIdentifier(
            $username,
        );

        $this->assertNotNull(
            $identity,
            'Tenant activity must not participate in global username credential lookup.',
        );

        $this->assertSame(
            $userId,
            $identity['user_id'] ?? null,
        );
    }

    private function insertPerson(
        string $personId,
        string $name,
    ): void {
        DB::table('persons')->insert([
            'id' => $personId,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(
        string $userId,
        string $personId,
        string $email,
        string $username,
        string $status,
    ): void {
        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => $email,
            'username' => $username,
            'password' => bcrypt('secret123'),
            'status' => $status,
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uniqueEmail(
        string $prefix,
    ): string {
        return sprintf(
            '%s-%s@educore.test',
            $prefix,
            Str::lower(
                Str::random(10),
            ),
        );
    }
}
