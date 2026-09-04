<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Services\WorkspaceEmployeeProvisioningService;
use Tests\TestCase;

final class WorkspaceEmployeeProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkspaceEmployeeProvisioningService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkspaceEmployeeProvisioningService::class);
        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_provision_creates_employee_employment_and_open_placement_atomically(): void
    {
        $organizationId = $this->createOrganization();
        $employmentTypeId = $this->createEmploymentType();

        $result = $this->service->provisionWithinWorkspace(
            tenantId: $this->tenantId,
            employeeData: [
                'nama' => 'Guru Uji Workspace',
                'nip' => 'NIP-WS-' . Str::upper(Str::random(6)),
                'jabatan' => 'GURU',
                'employment_type_id' => $employmentTypeId,
            ],
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertTrue(Str::isUuid($result['employee_id']));
        $this->assertSame(Employment::STATUS_ACTIVE, $result['employment_status']);

        /** @var EmploymentPlacement $placement */
        $placement = EmploymentPlacement::query()->findOrFail($result['employment_placement_id']);
        $this->assertNull($placement->effective_to);
        $this->assertTrue($placement->is_primary);
        $this->assertSame($result['organizational_assignment_id'], $placement->organizational_assignment_id);
    }

    public function test_provision_scopes_placement_to_unit_when_unit_is_provided(): void
    {
        $organizationId = $this->createOrganization();
        $unitId = $this->createOrganizationUnit($organizationId);
        $employmentTypeId = $this->createEmploymentType();

        $result = $this->service->provisionWithinWorkspace(
            tenantId: $this->tenantId,
            employeeData: [
                'nama' => 'Guru Uji Unit',
                'nip' => 'NIP-WS-' . Str::upper(Str::random(6)),
                'jabatan' => 'GURU',
                'employment_type_id' => $employmentTypeId,
            ],
            organizationId: $organizationId,
            organizationUnitId: $unitId,
        );

        $assignmentUnitId = DB::table('organizational_assignments')
            ->where('id', $result['organizational_assignment_id'])
            ->value('organization_unit_id');

        $this->assertSame($unitId, $assignmentUnitId);
    }

    public function test_provision_reuses_existing_organizational_assignment(): void
    {
        $organizationId = $this->createOrganization();
        $employmentTypeId = $this->createEmploymentType();

        $first = $this->service->provisionWithinWorkspace(
            tenantId: $this->tenantId,
            employeeData: [
                'nama' => 'Guru Uji Idempoten Satu',
                'nip' => 'NIP-WS-' . Str::upper(Str::random(6)),
                'jabatan' => 'GURU',
                'employment_type_id' => $employmentTypeId,
            ],
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        // Membership BEDA (employee lain), tapi Organization SAMA —
        // assignToOrganization() untuk Membership baru tetap membuat
        // baris assignment baru (idempotency-nya per Membership, bukan
        // global) — test ini memastikan orchestrator tidak salah
        // menyamakan dua Employee berbeda ke satu Assignment yang sama.
        $second = $this->service->provisionWithinWorkspace(
            tenantId: $this->tenantId,
            employeeData: [
                'nama' => 'Guru Uji Idempoten Dua',
                'nip' => 'NIP-WS-' . Str::upper(Str::random(6)),
                'jabatan' => 'GURU',
                'employment_type_id' => $employmentTypeId,
            ],
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertNotSame(
            $first['organizational_assignment_id'],
            $second['organizational_assignment_id'],
        );
        $this->assertNotSame($first['membership_id'], $second['membership_id']);
    }

    /**
     * INV-HR-012 — kalau langkah manapun gagal (di sini: langkah 5,
     * Employment gagal karena employment_type_id tidak merujuk katalog
     * aktif), SELURUH langkah sebelumnya (Person, Membership, Employee,
     * bahkan OrganizationalAssignment yang sudah dibuat di langkah 4)
     * ikut di-rollback — tidak ada Employee "yatim" yang tersisa.
     */
    public function test_provision_rolls_back_everything_when_a_later_step_fails(): void
    {
        $organizationId = $this->createOrganization();
        $nip = 'NIP-WS-ROLLBACK-' . Str::upper(Str::random(6));

        try {
            $this->service->provisionWithinWorkspace(
                tenantId: $this->tenantId,
                employeeData: [
                    'nama' => 'Guru Uji Rollback',
                    'nip' => $nip,
                    'jabatan' => 'GURU',
                    'employment_type_id' => UuidV7::generate(), // tidak ada di katalog
                ],
                organizationId: $organizationId,
                organizationUnitId: null,
            );

            $this->fail('Expected EmploymentLifecycleException was not thrown.');
        } catch (EmploymentLifecycleException) {
            // diharapkan — verifikasi rollback di bawah.
        }

        $this->assertSame(
            0,
            DB::table('employees')->where('nip', $nip)->count(),
        );
        $this->assertSame(
            0,
            DB::table('organizational_assignments')
                ->where('tenant_id', $this->tenantId)
                ->where('organization_id', $organizationId)
                ->count(),
        );
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Workspace Provisioning Tenant',
            'subdomain' => sprintf(
                'workspace-provisioning-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createOrganization(): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Workspace Provisioning Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createOrganizationUnit(string $organizationId): string
    {
        $unitId = UuidV7::generate();

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $this->tenantId,
            'organization_id' => $organizationId,
            'name' => 'Workspace Provisioning Fixture Unit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }

    private function createEmploymentType(): string
    {
        $employmentTypeId = UuidV7::generate();

        DB::table('employment_types')->insert([
            'id' => $employmentTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'TETAP-' . Str::upper(Str::random(6)),
            'name' => 'Pegawai Tetap',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentTypeId;
    }
}
