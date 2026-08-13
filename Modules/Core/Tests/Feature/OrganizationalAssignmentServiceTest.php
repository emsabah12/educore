<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Identity\Models\User;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentServiceInterface;
use Modules\Core\Organization\Exceptions\OrganizationalAssignmentException;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Organization\Repositories\EloquentOrganizationalAssignmentRepository;
use Modules\Core\Organization\Services\OrganizationalAssignmentService;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class OrganizationalAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_and_repository_bindings_resolve(): void
    {
        $this->assertInstanceOf(
            OrganizationalAssignmentRepositoryInterface::class,
            $this->app->make(
                OrganizationalAssignmentRepositoryInterface::class,
            ),
        );
        $this->assertInstanceOf(
            EloquentOrganizationalAssignmentRepository::class,
            $this->app->make(
                OrganizationalAssignmentRepositoryInterface::class,
            ),
        );
        $this->assertInstanceOf(
            OrganizationalAssignmentServiceInterface::class,
            $this->app->make(
                OrganizationalAssignmentServiceInterface::class,
            ),
        );
        $this->assertInstanceOf(
            OrganizationalAssignmentService::class,
            $this->app->make(
                OrganizationalAssignmentServiceInterface::class,
            ),
        );
    }

    public function test_assign_to_organization_is_idempotent(): void
    {
        [$tenant, $membership, $organization] =
            $this->createActiveContext();

        $service = $this->service();

        $first = $service->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );
        $second = $service->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );

        $this->assertSame(
            (string) $first->getKey(),
            (string) $second->getKey(),
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $first->tenant_id,
        );
        $this->assertSame(
            OrganizationalAssignment::STATUS_ACTIVE,
            $second->status,
        );
        $this->assertNull($second->organization_unit_id);
        $this->assertSame(
            1,
            OrganizationalAssignment::query()->count(),
        );
    }

    public function test_assign_to_organization_reactivates_existing_row(): void
    {
        [, $membership, $organization] =
            $this->createActiveContext();

        $service = $this->service();

        $assignment = $service->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );

        $assignment->update([
            'status' => OrganizationalAssignment::STATUS_INACTIVE,
        ]);

        $reactivated = $service->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );

        $this->assertSame(
            (string) $assignment->getKey(),
            (string) $reactivated->getKey(),
        );
        $this->assertSame(
            OrganizationalAssignment::STATUS_ACTIVE,
            $reactivated->status,
        );
        $this->assertSame(
            1,
            OrganizationalAssignment::query()->count(),
        );
    }

    public function test_assign_to_unit_is_idempotent_and_reactivates_existing_row(): void
    {
        [, $membership, $organization, $unit] =
            $this->createActiveContext();

        $service = $this->service();

        $first = $service->assignToUnit(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
            (string) $unit->getKey(),
        );
        $second = $service->assignToUnit(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
            (string) $unit->getKey(),
        );

        $this->assertSame(
            (string) $first->getKey(),
            (string) $second->getKey(),
        );

        $second->update([
            'status' => OrganizationalAssignment::STATUS_INACTIVE,
        ]);

        $reactivated = $service->assignToUnit(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
            (string) $unit->getKey(),
        );

        $this->assertSame(
            (string) $first->getKey(),
            (string) $reactivated->getKey(),
        );
        $this->assertSame(
            OrganizationalAssignment::STATUS_ACTIVE,
            $reactivated->status,
        );
        $this->assertSame(
            (string) $unit->getKey(),
            (string) $reactivated->organization_unit_id,
        );
        $this->assertSame(
            1,
            OrganizationalAssignment::query()->count(),
        );
    }

    public function test_assign_rejects_missing_tenant_context(): void
    {
        $tenant = $this->createTenant(
            'Missing Context Tenant',
            'missing-context-tenant',
        );
        $membership = $this->createMembership($tenant);

        $this->activateTenant($tenant);
        [$organization] = $this->createTopology();

        $this->tenantContext()->clear();

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );
    }

    public function test_assign_rejects_invalid_identifiers_before_persistence(): void
    {
        $tenant = $this->createTenant(
            'Identifier Tenant',
            'identifier-tenant',
        );
        $this->activateTenant($tenant);

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToOrganization(
            'not-a-uuid',
            'also-not-a-uuid',
        );
    }

    public function test_assign_rejects_inactive_membership(): void
    {
        [$tenant, $membership, $organization] =
            $this->createActiveContext();

        $membership->update([
            'status' => 'INACTIVE',
        ]);

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );
    }

    public function test_assign_rejects_inactive_organization(): void
    {
        [, $membership, $organization] =
            $this->createActiveContext();

        $organization->update([
            'is_active' => false,
        ]);

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );
    }

    public function test_assign_to_unit_rejects_inactive_unit(): void
    {
        [, $membership, $organization, $unit] =
            $this->createActiveContext();

        $unit->update([
            'is_active' => false,
        ]);

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToUnit(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
            (string) $unit->getKey(),
        );
    }

    public function test_assign_to_unit_rejects_unit_from_another_organization(): void
    {
        [, $membership, $organization] =
            $this->createActiveContext();

        [, $otherUnit] = $this->createTopology(
            'Other Organization',
            'Other Unit',
        );

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToUnit(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
            (string) $otherUnit->getKey(),
        );
    }

    public function test_assign_rejects_cross_tenant_membership(): void
    {
        $tenantA = $this->createTenant(
            'Membership Service Tenant A',
            'membership-service-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Membership Service Tenant B',
            'membership-service-tenant-b',
        );

        $membershipA = $this->createMembership($tenantA);

        $this->activateTenant($tenantB);
        [$organizationB] = $this->createTopology();

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToOrganization(
            (string) $membershipA->getKey(),
            (string) $organizationB->getKey(),
        );
    }

    public function test_assign_rejects_cross_tenant_organization(): void
    {
        $tenantA = $this->createTenant(
            'Organization Service Tenant A',
            'organization-service-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Organization Service Tenant B',
            'organization-service-tenant-b',
        );

        $this->activateTenant($tenantB);
        [$organizationB] = $this->createTopology();

        $this->activateTenant($tenantA);
        $membershipA = $this->createMembership($tenantA);

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->assignToOrganization(
            (string) $membershipA->getKey(),
            (string) $organizationB->getKey(),
        );
    }

    public function test_deactivate_is_idempotent(): void
    {
        [, $membership, $organization] =
            $this->createActiveContext();

        $service = $this->service();

        $assignment = $service->assignToOrganization(
            (string) $membership->getKey(),
            (string) $organization->getKey(),
        );

        $first = $service->deactivate(
            (string) $assignment->getKey(),
        );
        $second = $service->deactivate(
            (string) $assignment->getKey(),
        );

        $this->assertSame(
            (string) $assignment->getKey(),
            (string) $first->getKey(),
        );
        $this->assertSame(
            (string) $first->getKey(),
            (string) $second->getKey(),
        );
        $this->assertSame(
            OrganizationalAssignment::STATUS_INACTIVE,
            $first->status,
        );
        $this->assertSame(
            OrganizationalAssignment::STATUS_INACTIVE,
            $second->status,
        );
        $this->assertSame(
            1,
            OrganizationalAssignment::query()->count(),
        );
    }

    public function test_deactivate_rejects_assignment_from_another_tenant(): void
    {
        $tenantA = $this->createTenant(
            'Deactivate Tenant A',
            'deactivate-tenant-a',
        );
        $this->activateTenant($tenantA);

        $membershipA = $this->createMembership($tenantA);
        [$organizationA] = $this->createTopology();

        $assignment = $this->service()->assignToOrganization(
            (string) $membershipA->getKey(),
            (string) $organizationA->getKey(),
        );

        $tenantB = $this->createTenant(
            'Deactivate Tenant B',
            'deactivate-tenant-b',
        );
        $this->activateTenant($tenantB);

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->deactivate(
            (string) $assignment->getKey(),
        );
    }

    public function test_deactivate_rejects_invalid_identifier(): void
    {
        $tenant = $this->createTenant(
            'Deactivate Identifier Tenant',
            'deactivate-identifier-tenant',
        );
        $this->activateTenant($tenant);

        $this->expectException(
            OrganizationalAssignmentException::class,
        );

        $this->service()->deactivate('not-a-uuid');
    }

    /**
     * @return array{
     *     0: Tenant,
     *     1: Membership,
     *     2: Organization,
     *     3: OrganizationUnit
     * }
     */
    private function createActiveContext(): array
    {
        $tenant = $this->createTenant(
            'Assignment Service Tenant',
            'assignment-service-' . strtolower(
                substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
            ),
        );
        $this->activateTenant($tenant);

        $membership = $this->createMembership($tenant);
        [$organization, $unit] = $this->createTopology();

        return [
            $tenant,
            $membership,
            $organization,
            $unit,
        ];
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
        Tenant $tenant,
    ): Membership {
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
        string $organizationName = 'Service Organization',
        string $unitName = 'Service Unit',
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

    private function service(): OrganizationalAssignmentServiceInterface
    {
        return $this->app->make(
            OrganizationalAssignmentServiceInterface::class,
        );
    }
}
