<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Identity\Models\User;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class OrganizationalAssignmentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_organization_level_assignment(): void
    {
        $tenant = $this->createTenant(
            'Assignment Tenant',
            'assignment-tenant',
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organization] = $this->createTopology();

        $assignment = OrganizationalAssignment::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => null,
            'status' => 'ACTIVE',
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $assignment->getKey()),
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $assignment->tenant_id,
        );
        $this->assertNull($assignment->organization_unit_id);
        $this->assertSame(
            (string) $membership->getKey(),
            (string) $assignment->membership->getKey(),
        );
        $this->assertSame(
            (string) $organization->getKey(),
            (string) $assignment->organization->getKey(),
        );
    }

    public function test_it_persists_unit_specific_assignment(): void
    {
        $tenant = $this->createTenant(
            'Unit Assignment Tenant',
            'unit-assignment-tenant',
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organization, $unit] = $this->createTopology();

        $assignment = OrganizationalAssignment::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => (string) $unit->getKey(),
            'status' => 'ACTIVE',
        ]);

        $this->assertSame(
            (string) $unit->getKey(),
            (string) $assignment->organizationUnit->getKey(),
        );
        $this->assertSame(
            (string) $organization->getKey(),
            (string) $assignment->organization->getKey(),
        );
    }

    public function test_duplicate_organization_level_assignment_is_rejected(): void
    {
        $tenant = $this->createTenant(
            'Organization Duplicate Tenant',
            'organization-duplicate-tenant',
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organization] = $this->createTopology();

        $payload = [
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => null,
            'status' => 'ACTIVE',
        ];

        OrganizationalAssignment::query()->create($payload);

        $this->expectException(QueryException::class);

        OrganizationalAssignment::query()->create($payload);
    }

    public function test_duplicate_unit_assignment_is_rejected(): void
    {
        $tenant = $this->createTenant(
            'Unit Duplicate Tenant',
            'unit-duplicate-tenant',
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organization, $unit] = $this->createTopology();

        $payload = [
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => (string) $unit->getKey(),
            'status' => 'ACTIVE',
        ];

        OrganizationalAssignment::query()->create($payload);

        $this->expectException(QueryException::class);

        OrganizationalAssignment::query()->create($payload);
    }

    public function test_cross_tenant_membership_assignment_is_rejected(): void
    {
        $tenantA = $this->createTenant(
            'Membership Boundary A',
            'membership-boundary-a',
        );
        $tenantB = $this->createTenant(
            'Membership Boundary B',
            'membership-boundary-b',
        );

        $membershipA = $this->createMembership($tenantA);

        $this->activateTenant($tenantB);
        [$organizationB] = $this->createTopology();

        $this->expectException(QueryException::class);

        OrganizationalAssignment::query()->create([
            'membership_id' => (string) $membershipA->getKey(),
            'organization_id' => (string) $organizationB->getKey(),
            'organization_unit_id' => null,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_cross_tenant_organization_assignment_is_rejected(): void
    {
        $tenantA = $this->createTenant(
            'Organization Boundary A',
            'organization-boundary-a',
        );
        $tenantB = $this->createTenant(
            'Organization Boundary B',
            'organization-boundary-b',
        );

        $this->activateTenant($tenantB);
        [$organizationB] = $this->createTopology();

        $this->activateTenant($tenantA);
        $membershipA = $this->createMembership($tenantA);

        $this->expectException(QueryException::class);

        OrganizationalAssignment::query()->create([
            'membership_id' => (string) $membershipA->getKey(),
            'organization_id' => (string) $organizationB->getKey(),
            'organization_unit_id' => null,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_unit_must_belong_to_selected_organization(): void
    {
        $tenant = $this->createTenant(
            'Unit Scope Tenant',
            'unit-scope-tenant',
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organizationA] = $this->createTopology(
            'Organization A',
            'Unit A',
        );
        [, $unitB] = $this->createTopology(
            'Organization B',
            'Unit B',
        );

        $this->expectException(QueryException::class);

        OrganizationalAssignment::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organizationA->getKey(),
            'organization_unit_id' => (string) $unitB->getKey(),
            'status' => 'ACTIVE',
        ]);
    }

    public function test_invalid_assignment_status_is_rejected(): void
    {
        $tenant = $this->createTenant(
            'Status Constraint Tenant',
            'status-constraint-tenant',
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organization] = $this->createTopology();

        $this->expectException(QueryException::class);

        OrganizationalAssignment::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => null,
            'status' => 'PENDING',
        ]);
    }

    public function test_referenced_membership_cannot_be_hard_deleted(): void
    {
        [$membership] = $this->createAssignedTopology(
            'Membership Delete Tenant',
            'membership-delete-tenant',
        );

        $this->expectException(QueryException::class);

        $membership->delete();
    }

    public function test_referenced_unit_cannot_be_hard_deleted(): void
    {
        [, , $unit] = $this->createAssignedTopology(
            'Unit Delete Tenant',
            'unit-delete-tenant',
        );

        $this->expectException(QueryException::class);

        $unit->forceDelete();
    }

    public function test_referenced_organization_cannot_be_hard_deleted(): void
    {
        [, $organization] = $this->createAssignedTopology(
            'Organization Delete Tenant',
            'organization-delete-tenant',
        );

        $this->expectException(QueryException::class);

        $organization->forceDelete();
    }

    /**
     * @return array{0: Membership, 1: Organization, 2: OrganizationUnit}
     */
    private function createAssignedTopology(
        string $tenantName,
        string $subdomain,
    ): array {
        $tenant = $this->createTenant(
            $tenantName,
            $subdomain,
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organization, $unit] = $this->createTopology();

        OrganizationalAssignment::query()->create([
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => (string) $unit->getKey(),
            'status' => 'ACTIVE',
        ]);

        return [$membership, $organization, $unit];
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

    private function createMembership(Tenant $tenant): Membership
    {
        $user = User::factory()->create();

        return Membership::query()->create([
            'person_id' => (string) $user->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * @return array{0: Organization, 1: OrganizationUnit}
     */
    private function createTopology(
        string $organizationName = 'SMP ABC',
        string $unitName = 'Kampus Timur',
    ): array {
        $organization = Organization::query()->create([
            'name' => $organizationName,
            'is_active' => true,
        ]);

        $unit = OrganizationUnit::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => $unitName,
            'is_active' => true,
        ]);

        return [$organization, $unit];
    }

    private function activateTenant(Tenant $tenant): void
    {
        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);
    }
}
