<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\MembershipRole;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;
use RuntimeException;
use Tests\TestCase;

final class MembershipRoleRepositoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_role_is_a_relationship_entity(): void
    {
        $traits = class_uses_recursive(
            MembershipRole::class,
        );

        $this->assertNotContains(
            BelongsToTenant::class,
            $traits,
        );

        $model = new MembershipRole();

        $this->assertFalse(
            $model->usesTimestamps(),
        );
    }

    public function test_repository_reads_roles_only_for_matching_active_membership_tenant(): void
    {
        $tenantAId = $this->createTenant(
            'Role Tenant A',
            'role-tenant-a',
        );

        $tenantBId = $this->createTenant(
            'Role Tenant B',
            'role-tenant-b',
        );

        $userId = $this->createUser();

        $membershipId = $this->createMembership(
            userId: $userId,
            tenantId: $tenantAId,
        );

        $roleId = $this->createRole(
            'repository-role',
        );

        DB::table('membership_roles')->insert([
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);

        $repository = $this->app->make(
            MembershipRoleRepositoryInterface::class,
        );

        $matchingRoles = $repository->rolesForMembership(
            $membershipId,
            $tenantAId,
        );

        $crossTenantRoles = $repository->rolesForMembership(
            $membershipId,
            $tenantBId,
        );

        $this->assertCount(
            1,
            $matchingRoles,
        );

        $this->assertSame(
            $roleId,
            (string) $matchingRoles->first()?->getKey(),
        );

        $this->assertCount(
            0,
            $crossTenantRoles,
        );

        $this->assertTrue(
            $repository->membershipHasRole(
                $membershipId,
                $tenantAId,
                'repository-role',
            ),
        );

        $this->assertFalse(
            $repository->membershipHasRole(
                $membershipId,
                $tenantBId,
                'repository-role',
            ),
        );
    }

    public function test_assignment_rejects_cross_tenant_membership_and_remains_idempotent(): void
    {
        $tenantAId = $this->createTenant(
            'Assignment Tenant A',
            'assignment-tenant-a',
        );

        $tenantBId = $this->createTenant(
            'Assignment Tenant B',
            'assignment-tenant-b',
        );

        $userId = $this->createUser();

        $membershipId = $this->createMembership(
            userId: $userId,
            tenantId: $tenantAId,
        );

        $roleId = $this->createRole(
            'assignment-role',
        );

        $repository = $this->app->make(
            MembershipRoleRepositoryInterface::class,
        );

        try {
            $repository->assignRole(
                membershipId: $membershipId,
                tenantId: $tenantBId,
                roleId: $roleId,
            );

            $this->fail(
                'Cross-tenant role assignment should have failed.',
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Active membership was not found in the requested tenant.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing(
            'membership_roles',
            [
                'membership_id' => $membershipId,
                'role_id' => $roleId,
            ],
        );

        $repository->assignRole(
            membershipId: $membershipId,
            tenantId: $tenantAId,
            roleId: $roleId,
        );

        $repository->assignRole(
            membershipId: $membershipId,
            tenantId: $tenantAId,
            roleId: $roleId,
        );

        $this->assertSame(
            1,
            DB::table('membership_roles')
                ->where('membership_id', $membershipId)
                ->where('role_id', $roleId)
                ->count(),
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

    private function createUser(): string
    {
        $userId = UuidV7::generate();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Membership Role User',
            'email' => sprintf(
                'membership-role-%s@example.test',
                substr(
                    str_replace('-', '', $userId),
                    0,
                    12,
                ),
            ),
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

    private function createRole(
        string $name,
    ): string {
        $roleId = UuidV7::generate();

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => $name,
            'display_name' => ucwords(
                str_replace('-', ' ', $name),
            ),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    }
}
