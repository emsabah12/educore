<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Models\EmploymentClassification;
use Modules\HR\Models\EmploymentType;
use Tests\TestCase;

final class EmploymentCatalogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant('Catalog Tenant A');
        $this->tenantBId = $this->createTenant('Catalog Tenant B');
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_employment_type_can_be_created_for_active_tenant_context(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $employmentType = EmploymentType::create([
            'code' => 'TETAP',
            'name' => 'Pegawai Tetap',
            'description' => 'Hubungan kerja tetap tanpa batas waktu.',
            'is_active' => true,
        ]);

        $this->assertTrue(Str::isUuid($employmentType->id));
        $this->assertSame($this->tenantAId, $employmentType->tenant_id);
        $this->assertTrue($employmentType->is_active);

        $this->assertDatabaseHas('employment_types', [
            'id' => $employmentType->id,
            'tenant_id' => $this->tenantAId,
            'code' => 'TETAP',
        ]);
    }

    public function test_employment_classification_can_be_created_for_active_tenant_context(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $classification = EmploymentClassification::create([
            'code' => 'GTY',
            'name' => 'Guru Tetap Yayasan',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('employment_classifications', [
            'id' => $classification->id,
            'tenant_id' => $this->tenantAId,
            'code' => 'GTY',
        ]);
    }

    public function test_employment_type_code_must_be_unique_per_tenant(): void
    {
        $this->activateTenantContext($this->tenantAId);

        EmploymentType::create([
            'code' => 'KONTRAK',
            'name' => 'Pegawai Kontrak',
        ]);

        $this->expectException(QueryException::class);

        EmploymentType::create([
            'code' => 'KONTRAK',
            'name' => 'Pegawai Kontrak Duplikat',
        ]);
    }

    public function test_same_employment_type_code_is_allowed_across_different_tenants(): void
    {
        $this->activateTenantContext($this->tenantAId);
        EmploymentType::create([
            'code' => 'HONORER',
            'name' => 'Pegawai Honorer',
        ]);

        $this->activateTenantContext($this->tenantBId);
        $tenantBType = EmploymentType::create([
            'code' => 'HONORER',
            'name' => 'Pegawai Honorer',
        ]);

        $this->assertDatabaseHas('employment_types', [
            'id' => $tenantBType->id,
            'tenant_id' => $this->tenantBId,
            'code' => 'HONORER',
        ]);
    }

    public function test_tenant_scope_hides_employment_types_belonging_to_other_tenants(): void
    {
        $this->activateTenantContext($this->tenantAId);
        EmploymentType::create([
            'code' => 'PNS',
            'name' => 'Pegawai Negeri Sipil',
        ]);

        $this->activateTenantContext($this->tenantBId);

        $this->assertSame(
            0,
            EmploymentType::query()->count(),
        );
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(string $name): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'catalog-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }
}
