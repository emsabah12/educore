<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Identity\Models\User;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationalAssignmentRole;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class OrganizationalAssignmentRolePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_role_grant_is_minimal_relationship_entity(): void
    {
        [$assignment, $role] = $this->createScopedGrantFixture();

        $grant = OrganizationalAssignmentRole::query()->create([
            'organizational_assignment_id' => (string) $assignment->getKey(),
            'role_id' => (string) $role->getKey(),
        ]);

        $this->assertSame(
            (string) $assignment->getKey(),
            (string) $grant->organizationalAssignment->getKey(),
        );
        $this->assertSame(
            (string) $role->getKey(),
            (string) $grant->role->getKey(),
        );

        $this->assertFalse(
            Schema::hasColumn(
                'organizational_assignment_roles',
                'tenant_id',
            ),
        );
        $this->assertFalse(
            Schema::hasColumn(
                'organizational_assignment_roles',
                'membership_id',
            ),
        );
        $this->assertFalse(
            Schema::hasColumn(
                'organizational_assignment_roles',
                'organization_id',
            ),
        );
        $this->assertFalse(
            Schema::hasColumn(
                'organizational_assignment_roles',
                'organization_unit_id',
            ),
        );
        $this->assertFalse(
            Schema::hasColumn(
                'organizational_assignment_roles',
                'permission_id',
            ),
        );
        $this->assertFalse(
            Schema::hasColumn(
                'organizational_assignment_roles',
                'status',
            ),
        );
    }

    public function test_composite_primary_key_rejects_duplicate_scoped_role_grant(): void
    {
        [$assignment, $role] = $this->createScopedGrantFixture();

        $payload = [
            'organizational_assignment_id' => (string) $assignment->getKey(),
            'role_id' => (string) $role->getKey(),
        ];

        OrganizationalAssignmentRole::query()->create(
            $payload,
        );

        $this->expectException(QueryException::class);

        OrganizationalAssignmentRole::query()->create(
            $payload,
        );
    }

    public function test_same_global_role_can_be_granted_to_different_assignments(): void
    {
        [
            $tenant,
            $membership,
            $organization,
            $organizationAssignment,
            $role,
        ] = $this->createScopedGrantFixture(
            includeContext: true,
        );

        $unit = OrganizationUnit::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Scoped Role Unit',
            'is_active' => true,
        ]);

        $unitAssignment = OrganizationalAssignment::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => (string) $unit->getKey(),
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);

        OrganizationalAssignmentRole::query()->create([
            'organizational_assignment_id' => (string) $organizationAssignment->getKey(),
            'role_id' => (string) $role->getKey(),
        ]);

        OrganizationalAssignmentRole::query()->create([
            'organizational_assignment_id' => (string) $unitAssignment->getKey(),
            'role_id' => (string) $role->getKey(),
        ]);

        $this->assertSame(
            2,
            OrganizationalAssignmentRole::query()
                ->where('role_id', (string) $role->getKey())
                ->count(),
        );

        $this->assertSame(
            1,
            Role::query()
                ->whereKey((string) $role->getKey())
                ->count(),
        );
    }

    public function test_referenced_assignment_cannot_be_hard_deleted(): void
    {
        [$assignment, $role] = $this->createScopedGrantFixture();

        OrganizationalAssignmentRole::query()->create([
            'organizational_assignment_id' => (string) $assignment->getKey(),
            'role_id' => (string) $role->getKey(),
        ]);

        $this->expectException(QueryException::class);

        $assignment->delete();
    }

    public function test_referenced_role_cannot_be_deleted(): void
    {
        [$assignment, $role] = $this->createScopedGrantFixture();

        OrganizationalAssignmentRole::query()->create([
            'organizational_assignment_id' => (string) $assignment->getKey(),
            'role_id' => (string) $role->getKey(),
        ]);

        $this->expectException(QueryException::class);

        $role->delete();
    }

    /**
     * @return array{
     *     0: OrganizationalAssignment,
     *     1: Role
     * }|array{
     *     0: Tenant,
     *     1: Membership,
     *     2: Organization,
     *     3: OrganizationalAssignment,
     *     4: Role
     * }
     */
    private function createScopedGrantFixture(
        bool $includeContext = false,
    ): array {
        $tenant = Tenant::query()->create([
            'name' => 'Scoped Role Tenant',
            'subdomain' => 'scoped-role-' . strtolower(
                substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
            ),
            'is_active' => true,
        ]);

        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);

        $user = User::factory()->create();

        $membership = Membership::query()->create([
            'person_id' => (string) $user->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $organization = Organization::query()->create([
            'name' => 'Scoped Role Organization',
            'is_active' => true,
        ]);

        $assignment = OrganizationalAssignment::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => null,
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);

        $role = Role::query()->create([
            'name' => 'scoped-role-' . strtolower(
                substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
            ),
            'display_name' => 'Scoped Role',
            'description' => 'Scoped role persistence test.',
        ]);

        if ($includeContext) {
            return [
                $tenant,
                $membership,
                $organization,
                $assignment,
                $role,
            ];
        }

        return [
            $assignment,
            $role,
        ];
    }
}
