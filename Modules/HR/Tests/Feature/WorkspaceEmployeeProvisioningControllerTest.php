<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class WorkspaceEmployeeProvisioningControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $organizationId;
    private string $employmentTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();
        $this->organizationId = UuidV7::generate();

        $this->createTenantFixture();
        $this->createOperatorFixture();
        $this->createOrganizationFixture($this->organizationId);
        $this->employmentTypeId = $this->createEmploymentType(isActive: true);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_employee_with_active_employment_and_open_placement(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId, null);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route('api.v1.hr.workspace.employees.store', [], false),
                [
                    'nama' => 'Guru Baru Workspace',
                    'nip' => 'NIP-HTTP-' . Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                    'employment_type_id' => $this->employmentTypeId,
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.employment_status', 'ACTIVE');

        $employeeId = $response->json('data.employee_id');
        $placementId = $response->json('data.employment_placement_id');

        // Employee yang baru dibuat harus LANGSUNG visible di workspace
        // yang sama (INV-HR-012 — tidak pernah "yatim" tanpa Placement).
        $this->assertDatabaseHas('employment_placements', [
            'id' => $placementId,
            'effective_to' => null,
        ]);
        $this->assertNotNull($employeeId);
    }

    public function test_store_scopes_assignment_to_operators_unit_when_operator_is_unit_scoped(): void
    {
        $unitId = $this->createOrganizationUnit($this->organizationId);
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId, $unitId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route('api.v1.hr.workspace.employees.store', [], false),
                [
                    'nama' => 'Guru Baru Unit',
                    'nip' => 'NIP-HTTP-' . Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                    'employment_type_id' => $this->employmentTypeId,
                ],
            );

        $response->assertCreated();

        $assignmentId = $response->json('data.organizational_assignment_id');
        $assignmentUnitId = DB::table('organizational_assignments')
            ->where('id', $assignmentId)
            ->value('organization_unit_id');

        $this->assertSame($unitId, $assignmentUnitId);
    }

    public function test_store_returns_conflict_when_employment_type_is_inactive(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId, null);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $inactiveEmploymentTypeId = $this->createEmploymentType(isActive: false);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route('api.v1.hr.workspace.employees.store', [], false),
                [
                    'nama' => 'Guru Baru Konflik',
                    'nip' => 'NIP-HTTP-' . Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                    'employment_type_id' => $inactiveEmploymentTypeId,
                ],
            );

        $response
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('code', 'WORKSPACE_EMPLOYEE_PROVISIONING_CONFLICT');
    }

    public function test_store_requires_organizational_context_header(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.workspace.employees.store', [], false),
                [
                    'nama' => 'Guru Baru Tanpa Header',
                    'nip' => 'NIP-HTTP-' . Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                    'employment_type_id' => $this->employmentTypeId,
                ],
            );

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'ORGANIZATIONAL_CONTEXT_REQUIRED');
    }

    public function test_store_is_denied_without_scoped_permission_grant(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId, null);
        // Sengaja TIDAK memanggil grantScopedRole().

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route('api.v1.hr.workspace.employees.store', [], false),
                [
                    'nama' => 'Guru Baru Tanpa Izin',
                    'nip' => 'NIP-HTTP-' . Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                    'employment_type_id' => $this->employmentTypeId,
                ],
            );

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
    }

    public function test_store_validation_rejects_missing_employment_type_id(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId, null);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route('api.v1.hr.workspace.employees.store', [], false),
                [
                    'nama' => 'Guru Tanpa Employment Type',
                    'nip' => 'NIP-HTTP-' . Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                ],
            );

        $response->assertUnprocessable();
    }

    private function issueToken(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $this->operatorUserId,
                $this->tenantId,
                ['membership_id' => $this->operatorMembershipId],
            );
    }

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Workspace Employee Provisioning Tenant',
            'subdomain' => sprintf(
                'workspace-emp-provision-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOperatorFixture(): void
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Workspace Employee Provisioning Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'workspace-emp-provision-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrganizationFixture(string $organizationId): string
    {
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Workspace Employee Provisioning Fixture Organization',
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
            'name' => 'Workspace Employee Provisioning Fixture Unit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }

    private function createEmploymentType(bool $isActive): string
    {
        $employmentTypeId = UuidV7::generate();

        DB::table('employment_types')->insert([
            'id' => $employmentTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'TETAP-' . Str::upper(Str::random(6)),
            'name' => 'Pegawai Tetap',
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentTypeId;
    }

    /**
     * Assignment milik OPERATOR sendiri — dirujuk header
     * X-EduCore-Organizational-Assignment-Id untuk membentuk workspace.
     */
    private function createOperatorAssignment(
        string $organizationId,
        ?string $organizationUnitId,
    ): string {
        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->operatorMembershipId,
            'organization_id' => $organizationId,
            'organization_unit_id' => $organizationUnitId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $assignmentId;
    }

    private function grantScopedRole(
        string $assignmentId,
        string $roleName,
    ): void {
        $roleId = DB::table('roles')
            ->where('name', $roleName)
            ->value('id');

        DB::table('organizational_assignment_roles')->insertOrIgnore([
            'organizational_assignment_id' => $assignmentId,
            'role_id' => $roleId,
        ]);
    }
}
