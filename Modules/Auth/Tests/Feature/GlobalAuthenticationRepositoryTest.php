<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Repositories\AuthenticationRepository;
use Modules\Core\Support\Uuid\UuidV7;
use ReflectionMethod;
use Tests\TestCase;

final class GlobalAuthenticationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_exposes_global_login_identifier_lookup(): void
    {
        $this->assertTrue(
            method_exists(
                AuthenticationRepositoryInterface::class,
                'findActiveByLoginIdentifier',
            ),
            'AuthenticationRepositoryInterface must expose global identifier lookup.',
        );

        $method = new ReflectionMethod(
            AuthenticationRepositoryInterface::class,
            'findActiveByLoginIdentifier',
        );

        $parameters = $method->getParameters();

        $this->assertCount(
            1,
            $parameters,
            'Global authentication lookup must not require Tenant context.',
        );

        $this->assertSame(
            'identifier',
            $parameters[0]->getName(),
        );

        $this->assertSame(
            'string',
            (string) $parameters[0]->getType(),
        );
    }

    public function test_active_user_can_be_resolved_without_membership(): void
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();

        $email = sprintf(
            'global-auth-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->insertPerson(
            personId: $personId,
            name: 'Global Authentication User',
        );

        $this->insertUser(
            userId: $userId,
            personId: $personId,
            email: $email,
            status: 'ACTIVE',
        );

        $repository = new AuthenticationRepository();

        $identity = $repository->findActiveByLoginIdentifier(
            $email,
        );

        $this->assertNotNull(
            $identity,
            'Membership absence must not invalidate otherwise valid User credentials.',
        );
    }

    public function test_inactive_user_cannot_be_resolved(): void
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();

        $email = sprintf(
            'inactive-global-auth-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->insertPerson(
            personId: $personId,
            name: 'Inactive Authentication User',
        );

        $this->insertUser(
            userId: $userId,
            personId: $personId,
            email: $email,
            status: 'SUSPENDED',
        );

        $repository = new AuthenticationRepository();

        $identity = $repository->findActiveByLoginIdentifier(
            $email,
        );

        $this->assertNull(
            $identity,
            'Inactive User accounts must fail closed during global authentication lookup.',
        );
    }

    public function test_inactive_tenant_does_not_invalidate_global_identity_lookup(): void
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $email = sprintf(
            'inactive-tenant-auth-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->insertPerson(
            personId: $personId,
            name: 'Inactive Tenant Authentication User',
        );

        $this->insertUser(
            userId: $userId,
            personId: $personId,
            email: $email,
            status: 'ACTIVE',
        );

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Inactive Authentication Tenant',
            'subdomain' => sprintf(
                'inactive-auth-%s',
                Str::lower(Str::random(10)),
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
            $email,
        );

        $this->assertNotNull(
            $identity,
            'Tenant activity must not participate in global User credential lookup.',
        );
    }

    public function test_repository_normalizes_email_identifier_before_lookup(): void
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();

        $email = sprintf(
            'normalized-global-auth-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->insertPerson(
            personId: $personId,
            name: 'Normalized Authentication User',
        );

        $this->insertUser(
            userId: $userId,
            personId: $personId,
            email: $email,
            status: 'ACTIVE',
        );

        $repository = new AuthenticationRepository();

        $identity = $repository->findActiveByLoginIdentifier(
            sprintf(
                '  %s  ',
                strtoupper($email),
            ),
        );

        $this->assertNotNull(
            $identity,
            'Repository boundary must normalize the canonical login identifier.',
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
        string $status,
    ): void {
        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => $email,
            'password' => bcrypt('secret123'),
            'status' => $status,
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
