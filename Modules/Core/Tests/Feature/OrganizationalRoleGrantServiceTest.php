<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Identity\Models\User;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentRoleRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalRoleGrantServiceInterface;
use Modules\Core\Organization\Exceptions\OrganizationalRoleGrantException;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationalAssignmentRole;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Organization\Repositories\EloquentOrganizationalAssignmentRoleRepository;
use Modules\Core\Organization\Services\OrganizationalRoleGrantService;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class OrganizationalRoleGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_and_repository_bindings_resolve(): void
    {
        $this->assertInstanceOf(
            EloquentOrganizationalAssignmentRoleRepository::class,
            $this->app->make(
                OrganizationalAssignmentRoleRepositoryInterface::class,
            ),
        );

        $this->assertInstanceOf(
            OrganizationalRoleGrantService::class,
            $this->app->make(
                OrganizationalRoleGrantServiceInterface::class,
            ),
        );
    }

    public function test_assign_role_is_idempotent(): void
    {
        [, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $service = $this->service();

        $first = $service->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
        $second = $service->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );

        $this->assertSame(
            (string) $assignment->getKey(),
            (string) $first->organizational_assignment_id,
        );
        $this->assertSame(
            (string) $role->getKey(),
            (string) $first->role_id,
        );
        $this->assertSame(
            (string) $first->organizational_assignment_id,
            (string) $second->organizational_assignment_id,
        );
        $this->assertSame(
            1,
            OrganizationalAssignmentRole::query()->count(),
        );
    }

    public function test_assign_role_allows_target_membership_different_from_actor(): void
    {
        [$tenant, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $actor = User::factory()->create();

        Membership::query()->create([
            'person_id' => (string) $actor->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($actor);

        $grant = $this->service()->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );

        $this->assertSame(
            (string) $assignment->getKey(),
            (string) $grant->organizational_assignment_id,
        );
    }

    public function test_assign_role_rejects_missing_tenant_context(): void
    {
        [, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $this->tenantContext()->clear();

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
    }

    public function test_assign_role_rejects_inactive_tenant_context(): void
    {
        [$tenant, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $tenant->setAttribute('is_active', false);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
    }

    public function test_assign_role_rejects_invalid_identifiers(): void
    {
        $tenant = $this->createTenant(
            'Invalid Grant Tenant',
            'invalid-grant-tenant',
        );
        $this->activateTenant($tenant);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            'not-a-uuid',
            'also-not-a-uuid',
        );
    }

    public function test_assign_role_rejects_assignment_from_another_tenant(): void
    {
        [$tenantA, , , $assignmentA, $role] =
            $this->createActiveAssignmentFixture();

        $tenantB = $this->createTenant(
            'Grant Tenant B',
            'grant-tenant-b',
        );
        $this->activateTenant($tenantB);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignmentA->getKey(),
            (string) $role->getKey(),
        );

        $this->activateTenant($tenantA);
    }

    public function test_assign_role_rejects_inactive_assignment(): void
    {
        [, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $assignment->update([
            'status' => OrganizationalAssignment::STATUS_INACTIVE,
        ]);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
    }

    public function test_assign_role_rejects_inactive_target_membership(): void
    {
        [, $membership, , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $membership->update([
            'status' => 'INACTIVE',
        ]);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
    }

    public function test_assign_role_rejects_inactive_organization(): void
    {
        [, , $organization, $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $organization->update([
            'is_active' => false,
        ]);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
    }

    public function test_assign_role_rejects_inactive_unit(): void
    {
        [
            ,
            ,
            ,
            $assignment,
            $role,
            $unit,
        ] = $this->createActiveAssignmentFixture(
            withUnit: true,
        );

        $unit->update([
            'is_active' => false,
        ]);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
    }

    public function test_assign_role_rejects_missing_role(): void
    {
        [, , , $assignment] =
            $this->createActiveAssignmentFixture();

        $missingRoleId =
            \Modules\Core\Support\Uuid\UuidV7::generate();

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            $missingRoleId,
        );
    }

    public function test_revoke_role_is_idempotent(): void
    {
        [, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $service = $this->service();

        $service->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );

        $service->revokeRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );
        $service->revokeRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );

        $this->assertSame(
            0,
            OrganizationalAssignmentRole::query()->count(),
        );
    }

    public function test_revoke_role_allows_inactive_assignment(): void
    {
        [, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $service = $this->service();

        $service->assignRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );

        $assignment->update([
            'status' => OrganizationalAssignment::STATUS_INACTIVE,
        ]);

        $service->revokeRole(
            (string) $assignment->getKey(),
            (string) $role->getKey(),
        );

        $this->assertSame(
            0,
            OrganizationalAssignmentRole::query()->count(),
        );
    }

    public function test_revoke_role_does_not_require_existing_role_row(): void
    {
        [, , , $assignment, $role] =
            $this->createActiveAssignmentFixture();

        $roleId = (string) $role->getKey();

        $this->service()->assignRole(
            (string) $assignment->getKey(),
            $roleId,
        );

        OrganizationalAssignmentRole::query()
            ->where(
                'organizational_assignment_id',
                (string) $assignment->getKey(),
            )
            ->where('role_id', $roleId)
            ->delete();

        $role->delete();

        $this->service()->revokeRole(
            (string) $assignment->getKey(),
            $roleId,
        );

        $this->assertSame(
            0,
            OrganizationalAssignmentRole::query()->count(),
        );
    }

    public function test_revoke_role_rejects_assignment_from_another_tenant(): void
    {
        [$tenantA, , , $assignmentA, $role] =
            $this->createActiveAssignmentFixture();

        $tenantB = $this->createTenant(
            'Revoke Tenant B',
            'revoke-tenant-b',
        );
        $this->activateTenant($tenantB);

        $this->expectException(
            OrganizationalRoleGrantException::class,
        );

        $this->service()->revokeRole(
            (string) $assignmentA->getKey(),
            (string) $role->getKey(),
        );

        $this->activateTenant($tenantA);
    }

    /**
     * @return array{
     *     0: Tenant,
     *     1: Membership,
     *     2: Organization,
     *     3: OrganizationalAssignment,
     *     4: Role,
     *     5?: OrganizationUnit
     * }
     */
    private function createActiveAssignmentFixture(
        bool $withUnit = false,
    ): array {
        $tenant = $this->createTenant(
            'Organizational Role Grant Tenant',
            'organizational-role-grant-' . strtolower(
                substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
            ),
        );
        $this->activateTenant($tenant);

        $targetUser = User::factory()->create();

        $membership = Membership::query()->create([
            'person_id' => (string) $targetUser->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $organization = Organization::query()->create([
            'name' => 'Role Grant Organization',
            'is_active' => true,
        ]);

        $unit = null;

        if ($withUnit) {
            $unit = OrganizationUnit::query()->create([
                'organization_id' => (string) $organization->getKey(),
                'name' => 'Role Grant Unit',
                'is_active' => true,
            ]);
        }

        $assignment = OrganizationalAssignment::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => $unit !== null
                ? (string) $unit->getKey()
                : null,
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);

        $role = Role::query()->create([
            'name' => 'grant-role-' . strtolower(
                substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
            ),
            'display_name' => 'Grant Role',
            'description' => 'Scoped grant service test role.',
        ]);

        $result = [
            $tenant,
            $membership,
            $organization,
            $assignment,
            $role,
        ];

        if ($unit !== null) {
            $result[] = $unit;
        }

        return $result;
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

    private function activateTenant(
        Tenant $tenant,
    ): void {
        $this->tenantContext()->setCurrentTenant($tenant);
    }

    private function tenantContext(): TenantContextInterface
    {
        return $this->app->make(
            TenantContextInterface::class,
        );
    }

    private function service(): OrganizationalRoleGrantServiceInterface
    {
        return $this->app->make(
            OrganizationalRoleGrantServiceInterface::class,
        );
    }
}
