<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Models\Position;
use Tests\TestCase;

final class PositionCatalogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant('Position Tenant A');
        $this->tenantBId = $this->createTenant('Position Tenant B');
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_position_can_be_created_for_active_tenant_context(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $position = Position::create([
            'code' => 'GURU-MTK',
            'name' => 'Guru Matematika',
            'description' => 'Mengampu mata pelajaran Matematika.',
            'is_active' => true,
        ]);

        $this->assertTrue(Str::isUuid($position->id));
        $this->assertSame($this->tenantAId, $position->tenant_id);

        $this->assertDatabaseHas('positions', [
            'id' => $position->id,
            'tenant_id' => $this->tenantAId,
            'code' => 'GURU-MTK',
        ]);
    }

    public function test_position_code_must_be_unique_per_tenant(): void
    {
        $this->activateTenantContext($this->tenantAId);

        Position::create([
            'code' => 'KEPALA-TU',
            'name' => 'Kepala Tata Usaha',
        ]);

        $this->expectException(QueryException::class);

        Position::create([
            'code' => 'KEPALA-TU',
            'name' => 'Kepala Tata Usaha Duplikat',
        ]);
    }

    public function test_same_position_code_is_allowed_across_different_tenants(): void
    {
        $this->activateTenantContext($this->tenantAId);
        Position::create([
            'code' => 'WAKA-KURIKULUM',
            'name' => 'Wakil Kepala Kurikulum',
        ]);

        $this->activateTenantContext($this->tenantBId);
        $tenantBPosition = Position::create([
            'code' => 'WAKA-KURIKULUM',
            'name' => 'Wakil Kepala Kurikulum',
        ]);

        $this->assertDatabaseHas('positions', [
            'id' => $tenantBPosition->id,
            'tenant_id' => $this->tenantBId,
            'code' => 'WAKA-KURIKULUM',
        ]);
    }

    public function test_tenant_scope_hides_positions_belonging_to_other_tenants(): void
    {
        $this->activateTenantContext($this->tenantAId);
        Position::create([
            'code' => 'STAFF-ADM',
            'name' => 'Staff Administrasi',
        ]);

        $this->activateTenantContext($this->tenantBId);

        $this->assertSame(
            0,
            Position::query()->count(),
        );
    }

    /**
     * Regression guard untuk INV-HR-003 (HR-002 §7): "Position is not
     * authorization." Test ini sengaja dibuat supaya kalau di masa depan
     * ada developer (atau AI) yang menambahkan kolom role_id/permission_id
     * dsb ke tabel positions, test suite langsung gagal dan memaksa
     * peninjauan ulang keputusan arsitektur, bukan lolos diam-diam.
     */
    public function test_positions_table_never_contains_authorization_or_scope_columns(): void
    {
        $forbiddenColumns = [
            'role_id',
            'permission_id',
            'organization_id',
            'organization_unit_id',
            'subject_id',
            'class_id',
        ];

        $actualColumns = Schema::getColumnListing('positions');

        foreach ($forbiddenColumns as $forbiddenColumn) {
            $this->assertNotContains(
                $forbiddenColumn,
                $actualColumns,
                sprintf(
                    'Kolom "%s" tidak boleh ada di tabel positions (melanggar INV-HR-003).',
                    $forbiddenColumn,
                ),
            );
        }
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
                'position-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }
}
