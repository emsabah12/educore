<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Dormitory\Models\Dormitory;
use Tests\TestCase;

final class DormitoryPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dormitory_is_uuidv7_and_owned_by_tenant_and_organization(): void
    {
        $tenant = $this->createTenant(
            'Dormitory Tenant',
            'dormitory-tenant',
        );
        $this->activateTenant($tenant);

        $organization = Organization::query()->create([
            'name' => 'SMP ABC',
            'code' => 'SMP-ABC',
            'is_active' => true,
        ]);

        $dormitory = Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Asrama Putra',
            'code' => 'ASR-P',
            'is_active' => true,
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $dormitory->getKey()),
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $dormitory->tenant_id,
        );
        $this->assertSame(
            (string) $tenant->getKey(),
            (string) $dormitory->tenant->getKey(),
        );
        $this->assertSame(
            (string) $organization->getKey(),
            (string) $dormitory->organization->getKey(),
        );
        $this->assertNull($dormitory->organization_unit_id);
        $this->assertNull($dormitory->organizationUnit);
    }

    public function test_dormitory_may_belong_to_matching_organization_unit(): void
    {
        $tenant = $this->createTenant(
            'Dormitory Unit Tenant',
            'dormitory-unit-tenant',
        );
        $this->activateTenant($tenant);

        [$organization, $unit] = $this->createTopology(
            'SMA ABC',
            'Kampus Timur',
        );

        $dormitory = Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => (string) $unit->getKey(),
            'name' => 'Asrama Kampus Timur',
            'is_active' => true,
        ]);

        $this->assertSame(
            (string) $organization->getKey(),
            (string) $dormitory->organization->getKey(),
        );
        $this->assertSame(
            (string) $unit->getKey(),
            (string) $dormitory->organizationUnit?->getKey(),
        );
    }

    public function test_tenant_scope_isolates_dormitories(): void
    {
        $tenantA = $this->createTenant(
            'Dormitory Tenant A',
            'dormitory-tenant-a',
        );
        $tenantB = $this->createTenant(
            'Dormitory Tenant B',
            'dormitory-tenant-b',
        );

        $this->activateTenant($tenantA);
        $organizationA = Organization::query()->create([
            'name' => 'Organization A',
            'is_active' => true,
        ]);
        $dormitoryA = Dormitory::query()->create([
            'organization_id' => (string) $organizationA->getKey(),
            'name' => 'Dormitory A',
            'is_active' => true,
        ]);

        $this->activateTenant($tenantB);
        $organizationB = Organization::query()->create([
            'name' => 'Organization B',
            'is_active' => true,
        ]);
        $dormitoryB = Dormitory::query()->create([
            'organization_id' => (string) $organizationB->getKey(),
            'name' => 'Dormitory B',
            'is_active' => true,
        ]);

        $this->activateTenant($tenantA);

        $this->assertSame(
            [(string) $dormitoryA->getKey()],
            Dormitory::query()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
        $this->assertFalse(
            Dormitory::query()->whereKey($dormitoryB->getKey())->exists(),
        );
    }

    public function test_database_rejects_cross_tenant_organization_reference(): void
    {
        $tenantA = $this->createTenant(
            'Cross Tenant A',
            'dormitory-cross-a',
        );
        $tenantB = $this->createTenant(
            'Cross Tenant B',
            'dormitory-cross-b',
        );

        $this->activateTenant($tenantB);
        $organizationB = Organization::query()->create([
            'name' => 'Tenant B Organization',
            'is_active' => true,
        ]);

        $this->activateTenant($tenantA);

        $this->expectException(QueryException::class);

        Dormitory::query()->create([
            'organization_id' => (string) $organizationB->getKey(),
            'name' => 'Invalid Cross-Tenant Dormitory',
            'is_active' => true,
        ]);
    }

    public function test_database_rejects_unit_from_another_organization(): void
    {
        $tenant = $this->createTenant(
            'Unit Ownership Tenant',
            'unit-ownership-tenant',
        );
        $this->activateTenant($tenant);

        $organizationA = Organization::query()->create([
            'name' => 'Organization A',
            'is_active' => true,
        ]);
        [$organizationB, $unitB] = $this->createTopology(
            'Organization B',
            'Unit B',
        );

        $this->assertNotSame(
            (string) $organizationA->getKey(),
            (string) $organizationB->getKey(),
        );

        $this->expectException(QueryException::class);

        Dormitory::query()->create([
            'organization_id' => (string) $organizationA->getKey(),
            'organization_unit_id' => (string) $unitB->getKey(),
            'name' => 'Invalid Unit Ownership Dormitory',
            'is_active' => true,
        ]);
    }

    public function test_hard_delete_of_organization_with_dormitory_is_restricted(): void
    {
        $tenant = $this->createTenant(
            'Dormitory Delete Tenant',
            'dormitory-delete-tenant',
        );
        $this->activateTenant($tenant);

        $organization = Organization::query()->create([
            'name' => 'Protected Organization',
            'is_active' => true,
        ]);
        Dormitory::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Protected Dormitory',
            'is_active' => true,
        ]);

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
