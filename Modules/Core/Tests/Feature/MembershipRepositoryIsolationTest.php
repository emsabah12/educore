<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Traits\BelongsToTenant;
use Tests\TestCase;

final class MembershipRepositoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_is_explicit_person_owned_tenant_boundary(): void
    {
        $traits = class_uses_recursive(
            Membership::class,
        );

        $this->assertNotContains(
            BelongsToTenant::class,
            $traits,
            'Membership must not depend on ambient TenantContext.',
        );

        $user = User::factory()->create();
        $tenant = $this->createTenant(
            'Membership UUID Tenant',
            'membership-uuid-tenant',
        );

        $membership = Membership::query()->create([
            'person_id' => $user->person_id,
            'tenant_id' => $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $membership->getKey()),
        );
        $this->assertSame(
            (string) $user->person_id,
            (string) $membership->person_id,
        );
        $this->assertSame(
            (string) $user->person_id,
            (string) $membership->person->getKey(),
        );
    }

    public function test_repository_resolves_memberships_without_ambient_tenant_context(): void
    {
        $tenantA = $this->createTenant(
            'Repository Tenant A',
            'repository-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Repository Tenant B',
            'repository-tenant-b',
        );
        $user = User::factory()->create();

        $membershipA = $this->createMembership(
            (string) $user->person_id,
            (string) $tenantA->getKey(),
        );
        $membershipB = $this->createMembership(
            (string) $user->person_id,
            (string) $tenantB->getKey(),
        );

        $tenantContext = $this->app->make(
            TenantContextInterface::class,
        );
        $tenantContext->clear();

        $repository = $this->app->make(
            MembershipRepositoryInterface::class,
        );

        $resolvedA = $repository->findActiveMembershipByIdAndTenant(
            (string) $membershipA->getKey(),
            (string) $tenantA->getKey(),
        );
        $resolvedB = $repository->findActiveMembershipByIdAndTenant(
            (string) $membershipB->getKey(),
            (string) $tenantB->getKey(),
        );

        $this->assertNotNull($resolvedA);
        $this->assertNotNull($resolvedB);
        $this->assertSame(
            (string) $membershipA->getKey(),
            (string) $resolvedA->getKey(),
        );
        $this->assertSame(
            (string) $membershipB->getKey(),
            (string) $resolvedB->getKey(),
        );
    }

    public function test_repository_rejects_cross_tenant_and_cross_person_lookup(): void
    {
        $tenantA = $this->createTenant(
            'Boundary Tenant A',
            'boundary-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Boundary Tenant B',
            'boundary-tenant-b',
        );
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $membership = $this->createMembership(
            (string) $owner->person_id,
            (string) $tenantA->getKey(),
        );

        $repository = $this->app->make(
            MembershipRepositoryInterface::class,
        );

        $this->assertNull(
            $repository->findActiveMembershipByIdAndTenant(
                (string) $membership->getKey(),
                (string) $tenantB->getKey(),
            ),
        );

        $this->assertNull(
            $repository->findActiveMembershipByIdForPerson(
                (string) $membership->getKey(),
                (string) $other->person_id,
            ),
        );

        $this->assertNotNull(
            $repository->findActiveMembershipByIdForPerson(
                (string) $membership->getKey(),
                (string) $owner->person_id,
            ),
        );
    }

    private function createTenant(
        string $name,
        string $subdomain,
    ): Tenant {
        return Tenant::query()->create([
            'name' => $name,
            'subdomain' => $subdomain,
            'is_active' => true,
        ]);
    }

    private function createMembership(
        string $personId,
        string $tenantId,
    ): Membership {
        return Membership::query()->create([
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
        ]);
    }
}
