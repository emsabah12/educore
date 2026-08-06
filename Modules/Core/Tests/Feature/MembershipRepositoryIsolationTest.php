<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Traits\BelongsToTenant;
use Tests\TestCase;

final class MembershipRepositoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_is_an_explicit_tenant_bound_aggregate(): void
    {
        $traits = class_uses_recursive(
            Membership::class,
        );

        $this->assertNotContains(
            BelongsToTenant::class,
            $traits,
            'Membership must not depend on ambient TenantContext.',
        );
    }

    public function test_repository_resolves_membership_without_tenant_context(): void
    {
        $tenantAId = $this->createTenant(
            'Repository Tenant A',
            'repository-tenant-a',
        );

        $tenantBId = $this->createTenant(
            'Repository Tenant B',
            'repository-tenant-b',
        );

        $userId = $this->createUser(
            'repository-user@example.test',
        );

        $membershipAId = $this->createMembership(
            userId: $userId,
            tenantId: $tenantAId,
        );

        $membershipBId = $this->createMembership(
            userId: $userId,
            tenantId: $tenantBId,
        );

        $tenantContext = $this->app->make(
            TenantContextInterface::class,
        );

        $tenantContext->clear();

        $this->assertNull(
            $tenantContext->getCurrentTenantId(),
        );

        $repository = $this->app->make(
            MembershipRepositoryInterface::class,
        );

        $membershipA = $repository->findActiveMembership(
            $userId,
            $tenantAId,
        );

        $membershipB = $repository->findActiveMembership(
            $userId,
            $tenantBId,
        );

        $this->assertNotNull(
            $membershipA,
        );

        $this->assertNotNull(
            $membershipB,
        );

        $this->assertSame(
            $membershipAId,
            (string) $membershipA->getKey(),
        );

        $this->assertSame(
            $membershipBId,
            (string) $membershipB->getKey(),
        );

        $this->assertSame(
            $tenantAId,
            (string) $membershipA->tenant_id,
        );

        $this->assertSame(
            $tenantBId,
            (string) $membershipB->tenant_id,
        );
    }

    public function test_repository_rejects_cross_tenant_and_cross_user_lookup(): void
    {
        $tenantAId = $this->createTenant(
            'Boundary Tenant A',
            'boundary-tenant-a',
        );

        $tenantBId = $this->createTenant(
            'Boundary Tenant B',
            'boundary-tenant-b',
        );

        $ownerUserId = $this->createUser(
            'membership-owner@example.test',
        );

        $otherUserId = $this->createUser(
            'membership-other@example.test',
        );

        $membershipId = $this->createMembership(
            userId: $ownerUserId,
            tenantId: $tenantAId,
        );

        $repository = $this->app->make(
            MembershipRepositoryInterface::class,
        );

        $crossTenantResult =
            $repository->findActiveMembershipByIdAndTenant(
                $membershipId,
                $tenantBId,
            );

        $crossUserResult =
            $repository->findActiveMembershipByIdForUser(
                $membershipId,
                $otherUserId,
            );

        $correctTenantResult =
            $repository->findActiveMembershipByIdAndTenant(
                $membershipId,
                $tenantAId,
            );

        $correctOwnerResult =
            $repository->findActiveMembershipByIdForUser(
                $membershipId,
                $ownerUserId,
            );

        $this->assertNull(
            $crossTenantResult,
        );

        $this->assertNull(
            $crossUserResult,
        );

        $this->assertNotNull(
            $correctTenantResult,
        );

        $this->assertNotNull(
            $correctOwnerResult,
        );

        $this->assertSame(
            $membershipId,
            (string) $correctTenantResult->getKey(),
        );

        $this->assertSame(
            $membershipId,
            (string) $correctOwnerResult->getKey(),
        );
    }

    private function createTenant(
        string $name,
        string $subdomain,
    ): string {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => $subdomain,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createUser(
        string $email,
    ): string {
        $userId = UuidV7::generate();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Membership Repository User',
            'email' => $email,
            'password' => 'not-used-by-this-test',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function createMembership(
        string $userId,
        string $tenantId,
    ): string {
        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => 'legacy-member',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }
}
