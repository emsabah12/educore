<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class OrganizationTopologyPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_and_unit_are_uuidv7_tenant_owned_entities(): void
    {
        $tenant = $this->createTenant(
            'Topology Tenant',
            'topology-tenant',
        );
        $this->activateTenant($tenant);

        $organization = Organization::query()->create([
            'name' => 'SMP ABC',
            'code' => 'SMP-ABC',
            'is_active' => true,
        ]);

        $unit = OrganizationUnit::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Kampus Timur',
            'code' => 'TIMUR',
            'is_active' => true,
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $organization->getKey()),
        );
        $this->assertTrue(
            UuidV7::validate((string) $unit->getKey()),
        );

        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $organization->tenant_id,
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $unit->tenant_id,
        );
        $this->assertSame(
            (string) $organization->getKey(),
            (string) $unit->organization->getKey(),
        );
    }

    public function test_tenant_scope_isolates_organizations_and_units(): void
    {
        $tenantA = $this->createTenant(
            'Organization Tenant A',
            'organization-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Organization Tenant B',
            'organization-tenant-b',
        );

        $this->activateTenant($tenantA);
        [$organizationA, $unitA] = $this->createTopology(
            'Organization A',
            'Unit A',
        );

        $this->activateTenant($tenantB);
        [$organizationB, $unitB] = $this->createTopology(
            'Organization B',
            'Unit B',
        );

        $this->activateTenant($tenantA);

        $this->assertSame(
            [(string) $organizationA->getKey()],
            Organization::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
        $this->assertSame(
            [(string) $unitA->getKey()],
            OrganizationUnit::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );

        $this->assertFalse(
            Organization::query()->whereKey(
                $organizationB->getKey(),
            )->exists(),
        );
        $this->assertFalse(
            OrganizationUnit::query()->whereKey(
                $unitB->getKey(),
            )->exists(),
        );
    }

    public function test_database_rejects_cross_tenant_organization_unit_reference(): void
    {
        $tenantA = $this->createTenant(
            'Cross Tenant A',
            'cross-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Cross Tenant B',
            'cross-tenant-b',
        );

        $this->activateTenant($tenantB);
        $organizationB = Organization::query()->create([
            'name' => 'Tenant B Organization',
            'is_active' => true,
        ]);

        $this->activateTenant($tenantA);

        $this->expectException(QueryException::class);

        OrganizationUnit::query()->create([
            'organization_id' => (string) $organizationB->getKey(),
            'name' => 'Invalid Cross-Tenant Unit',
            'is_active' => true,
        ]);
    }

    public function test_hard_delete_of_organization_with_units_is_restricted(): void
    {
        $tenant = $this->createTenant(
            'Delete Restriction Tenant',
            'delete-restriction-tenant',
        );
        $this->activateTenant($tenant);

        [$organization] = $this->createTopology(
            'Protected Organization',
            'Protected Unit',
        );

        $this->expectException(QueryException::class);

        $organization->forceDelete();
    }

    /**
     * @return array{0: Organization, 1: OrganizationUnit}
     */
    private function createTopology(
        string $organizationName,
        string $unitName,
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

    private function activateTenant(Tenant $tenant): void
    {
        $this->app->make(
            TenantContextInterface::class,
        )->setCurrentTenant($tenant);
    }
}
