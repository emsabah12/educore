<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAuthorizationServiceInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Contracts\OrganizationalScopedRoleRepositoryInterface;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationalAssignmentRole;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Organization\Repositories\EloquentOrganizationalScopedRoleRepository;
use Modules\Core\Organization\Services\OrganizationalAuthorizationService;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class OrganizationalAuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bindings_resolve(): void
    {
        $this->assertInstanceOf(
            EloquentOrganizationalScopedRoleRepository::class,
            $this->app->make(
                OrganizationalScopedRoleRepositoryInterface::class,
            ),
        );

        $this->assertInstanceOf(
            OrganizationalAuthorizationService::class,
            $this->app->make(
                OrganizationalAuthorizationServiceInterface::class,
            ),
        );
    }

    public function test_missing_organizational_context_fails_closed(): void
    {
        $this->assertFalse(
            $this->service()->hasRole('tenant-admin'),
        );
        $this->assertFalse(
            $this->service()->hasPermission('student.update'),
        );
    }

    public function test_empty_role_and_permission_names_fail_closed(): void
    {
        $this->assertFalse(
            $this->service()->hasRole('   '),
        );
        $this->assertFalse(
            $this->service()->hasPermission('   '),
        );
    }

    public function test_tenant_wide_role_is_effective_in_organization_context(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: false,
        );

        $role = $this->createRole('tenant-auditor');

        $this->app->make(
            MembershipRoleRepositoryInterface::class,
        )->assignRole(
            (string) $fixture['membership']->getKey(),
            (string) $fixture['tenant']->getKey(),
            (string) $role->getKey(),
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertTrue(
            $this->service()->hasRole('tenant-auditor'),
        );
    }

    public function test_organization_role_is_effective_in_organization_context(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: false,
        );

        $role = $this->createRole('organization-manager');

        $this->grantRole(
            $fixture['contextAssignment'],
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertTrue(
            $this->service()->hasRole('organization-manager'),
        );
    }

    public function test_organization_role_is_inherited_into_unit_context(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: true,
        );

        $role = $this->createRole('organization-manager');

        $organizationAssignment =
            $this->createOrganizationAssignment(
                $fixture['tenant'],
                $fixture['membership'],
                $fixture['organization'],
            );

        $this->grantRole(
            $organizationAssignment,
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertTrue(
            $this->service()->hasRole('organization-manager'),
        );
    }

    public function test_exact_unit_role_is_effective_in_matching_unit_context(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: true,
        );

        $role = $this->createRole('unit-operator');

        $this->grantRole(
            $fixture['contextAssignment'],
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertTrue(
            $this->service()->hasRole('unit-operator'),
        );
    }

    public function test_unit_role_is_not_effective_in_organization_context(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: false,
        );

        $unit = $this->createUnit(
            $fixture['organization'],
        );
        $unitAssignment = $this->createUnitAssignment(
            $fixture['tenant'],
            $fixture['membership'],
            $fixture['organization'],
            $unit,
        );

        $role = $this->createRole('unit-only-role');

        $this->grantRole(
            $unitAssignment,
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertFalse(
            $this->service()->hasRole('unit-only-role'),
        );
    }

    public function test_sibling_unit_role_is_not_effective(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: true,
        );

        $siblingUnit = $this->createUnit(
            $fixture['organization'],
            'Sibling Unit',
        );
        $siblingAssignment = $this->createUnitAssignment(
            $fixture['tenant'],
            $fixture['membership'],
            $fixture['organization'],
            $siblingUnit,
        );

        $role = $this->createRole('sibling-operator');

        $this->grantRole(
            $siblingAssignment,
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertFalse(
            $this->service()->hasRole('sibling-operator'),
        );
    }

    public function test_role_from_another_organization_is_not_effective(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: false,
        );

        $otherOrganization = Organization::query()->create([
            'name' => 'Other Organization',
            'is_active' => true,
        ]);

        $otherAssignment =
            $this->createOrganizationAssignment(
                $fixture['tenant'],
                $fixture['membership'],
                $otherOrganization,
            );

        $role = $this->createRole('other-organization-role');

        $this->grantRole(
            $otherAssignment,
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertFalse(
            $this->service()->hasRole(
                'other-organization-role',
            ),
        );
    }

    public function test_role_from_another_membership_is_not_effective(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: false,
        );

        $otherUser = \Modules\Core\Identity\Models\User::factory()
            ->create();

        $otherMembership = Membership::query()->create([
            'person_id' => (string) $otherUser->person_id,
            'tenant_id' => (string) $fixture['tenant']->getKey(),
            'status' => 'ACTIVE',
        ]);

        $otherAssignment =
            $this->createOrganizationAssignment(
                $fixture['tenant'],
                $otherMembership,
                $fixture['organization'],
            );

        $role = $this->createRole('other-membership-role');

        $this->grantRole(
            $otherAssignment,
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertFalse(
            $this->service()->hasRole(
                'other-membership-role',
            ),
        );
    }

    public function test_inactive_parent_assignment_role_is_not_effective(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: true,
        );

        $parentAssignment =
            $this->createOrganizationAssignment(
                $fixture['tenant'],
                $fixture['membership'],
                $fixture['organization'],
            );

        $role = $this->createRole('dormant-parent-role');

        $this->grantRole(
            $parentAssignment,
            $role,
        );

        $parentAssignment->update([
            'status' => OrganizationalAssignment::STATUS_INACTIVE,
        ]);

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertFalse(
            $this->service()->hasRole('dormant-parent-role'),
        );
    }

    public function test_stale_current_context_is_revalidated_and_denied(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: false,
        );

        $role = $this->createRole('stale-role');

        $this->grantRole(
            $fixture['contextAssignment'],
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $fixture['contextAssignment']->update([
            'status' => OrganizationalAssignment::STATUS_INACTIVE,
        ]);

        $this->assertFalse(
            $this->service()->hasRole('stale-role'),
        );
    }

    public function test_duplicate_grant_paths_are_deduplicated(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: true,
        );

        $role = $this->createRole('shared-role');

        $this->app->make(
            MembershipRoleRepositoryInterface::class,
        )->assignRole(
            (string) $fixture['membership']->getKey(),
            (string) $fixture['tenant']->getKey(),
            (string) $role->getKey(),
        );

        $parentAssignment =
            $this->createOrganizationAssignment(
                $fixture['tenant'],
                $fixture['membership'],
                $fixture['organization'],
            );

        $this->grantRole($parentAssignment, $role);
        $this->grantRole(
            $fixture['contextAssignment'],
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $roles = $this->app->make(
            OrganizationalScopedRoleRepositoryInterface::class,
        )->rolesForContext(
            (string) $fixture['tenant']->getKey(),
            (string) $fixture['membership']->getKey(),
            (string) $fixture['organization']->getKey(),
            (string) $fixture['unit']->getKey(),
        );

        $this->assertSame(
            1,
            $roles
                ->where('name', 'shared-role')
                ->count(),
        );

        $this->assertTrue(
            $this->service()->hasRole('shared-role'),
        );
    }

    public function test_permission_is_granted_through_effective_scoped_role(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: true,
        );

        $role = $this->createRole('report-publisher');
        $permission = $this->createPermission(
            'report-card.publish',
        );

        \Illuminate\Support\Facades\DB::table(
            'role_permissions',
        )->insert([
            'role_id' => (string) $role->getKey(),
            'permission_id' => (string) $permission->getKey(),
        ]);

        $this->grantRole(
            $fixture['contextAssignment'],
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertTrue(
            $this->service()->hasPermission(
                'report-card.publish',
            ),
        );
    }

    public function test_permission_is_not_granted_from_sibling_unit_role(): void
    {
        $fixture = $this->createContextFixture(
            withUnit: true,
        );

        $siblingUnit = $this->createUnit(
            $fixture['organization'],
            'Permission Sibling Unit',
        );
        $siblingAssignment = $this->createUnitAssignment(
            $fixture['tenant'],
            $fixture['membership'],
            $fixture['organization'],
            $siblingUnit,
        );

        $role = $this->createRole('sibling-publisher');
        $permission = $this->createPermission(
            'report-card.publish',
        );

        \Illuminate\Support\Facades\DB::table(
            'role_permissions',
        )->insert([
            'role_id' => (string) $role->getKey(),
            'permission_id' => (string) $permission->getKey(),
        ]);

        $this->grantRole(
            $siblingAssignment,
            $role,
        );

        $this->resolveContext($fixture['contextAssignment']);

        $this->assertFalse(
            $this->service()->hasPermission(
                'report-card.publish',
            ),
        );
    }

    /**
     * @return array{
     *     tenant: Tenant,
     *     membership: Membership,
     *     organization: Organization,
     *     contextAssignment: OrganizationalAssignment,
     *     unit?: OrganizationUnit
     * }
     */
    private function createContextFixture(
        bool $withUnit,
    ): array {
        $tenant = Tenant::query()->create([
            'name' => 'Scoped Authorization Tenant',
            'subdomain' => 'scoped-auth-' . strtolower(
                substr(
                    (string) \Illuminate\Support\Str::uuid(),
                    0,
                    8,
                ),
            ),
            'is_active' => true,
        ]);

        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);

        $user = \Modules\Core\Identity\Models\User::factory()
            ->create();

        $membership = Membership::query()->create([
            'person_id' => (string) $user->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $organization = Organization::query()->create([
            'name' => 'Scoped Authorization Organization',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        request()->attributes->set(
            'authenticated_membership_id',
            (string) $membership->getKey(),
        );

        if (! $withUnit) {
            $contextAssignment =
                $this->createOrganizationAssignment(
                    $tenant,
                    $membership,
                    $organization,
                );

            return [
                'tenant' => $tenant,
                'membership' => $membership,
                'organization' => $organization,
                'contextAssignment' => $contextAssignment,
            ];
        }

        $unit = $this->createUnit($organization);

        $contextAssignment =
            $this->createUnitAssignment(
                $tenant,
                $membership,
                $organization,
                $unit,
            );

        return [
            'tenant' => $tenant,
            'membership' => $membership,
            'organization' => $organization,
            'contextAssignment' => $contextAssignment,
            'unit' => $unit,
        ];
    }

    private function createOrganizationAssignment(
        Tenant $tenant,
        Membership $membership,
        Organization $organization,
    ): OrganizationalAssignment {
        return OrganizationalAssignment::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => null,
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);
    }

    private function createUnitAssignment(
        Tenant $tenant,
        Membership $membership,
        Organization $organization,
        OrganizationUnit $unit,
    ): OrganizationalAssignment {
        return OrganizationalAssignment::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => (string) $unit->getKey(),
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);
    }

    private function createUnit(
        Organization $organization,
        string $name = 'Scoped Authorization Unit',
    ): OrganizationUnit {
        return OrganizationUnit::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createRole(
        string $name,
    ): Role {
        return Role::query()->create([
            'name' => $name,
            'display_name' => ucwords(
                str_replace('-', ' ', $name),
            ),
            'description' => 'Scoped authorization test role.',
        ]);
    }

    private function createPermission(
        string $name,
    ): Permission {
        return Permission::query()->create([
            'name' => $name,
            'display_name' => ucwords(
                str_replace(['.', '-'], ' ', $name),
            ),
            'description' =>
                'Scoped authorization test permission.',
        ]);
    }

    private function grantRole(
        OrganizationalAssignment $assignment,
        Role $role,
    ): void {
        OrganizationalAssignmentRole::query()->create([
            'organizational_assignment_id' =>
                (string) $assignment->getKey(),
            'role_id' => (string) $role->getKey(),
        ]);
    }

    private function resolveContext(
        OrganizationalAssignment $assignment,
    ): void {
        $this->app->make(
            OrganizationalContextResolverInterface::class,
        )->resolve(
            (string) $assignment->getKey(),
        );
    }

    private function service(): OrganizationalAuthorizationServiceInterface
    {
        return $this->app->make(
            OrganizationalAuthorizationServiceInterface::class,
        );
    }
}
