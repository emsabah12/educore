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
use Modules\HR\Database\Seeders\EmployeeSelfServiceRoleSeeder;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class EmployeeOrganizationalAssignmentsControllerTest extends TestCase
{
    use GrantsSubscriptionFeature;
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
        $this->seed(EmployeeSelfServiceRoleSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();
        $this->organizationId = UuidV7::generate();

        $this->createTenantFixture();
        $this->grantTenantFeature($this->tenantId, 'hr_module');
        $this->createOperatorFixture();
        $this->createOrganizationFixture($this->organizationId, 'Induk');
        $this->employmentTypeId = $this->createEmploymentType();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_lists_only_active_assignments_belonging_to_the_employees_own_membership(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $employeeId = $this->createEmployee($operatorAssignmentId);
        $employeeMembershipId = DB::table('employees')
            ->where('id', $employeeId)
            ->value('membership_id');

        // Catatan: createEmployee() (Tambah Pegawai) SUDAH otomatis
        // membuat 1 organizational_assignment ACTIVE org-level (tanpa
        // unit) sebagai bagian transaksi provisioning — lihat
        // WorkspaceEmployeeProvisioningService langkah 4. Baris kedua
        // di bawah ini (dengan unit) menguji bahwa BEBERAPA assignment
        // ACTIVE untuk membership yang sama semuanya terkumpul dengan
        // benar (bukan cuma satu), plus INACTIVE dan milik membership
        // lain tetap tersaring keluar.
        $unitId = UuidV7::generate();

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $this->tenantId,
            'organization_id' => $this->organizationId,
            'name' => 'Unit Kurikulum',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ACTIVE, kedua — HARUS ikut muncul (selain yang auto-created).
        DB::table('organizational_assignments')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'membership_id' => $employeeMembershipId,
            'organization_id' => $this->organizationId,
            'organization_unit_id' => $unitId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // INACTIVE — TIDAK boleh muncul.
        $inactiveOrgId = UuidV7::generate();
        $this->createOrganizationFixture($inactiveOrgId, 'Inaktif');

        DB::table('organizational_assignments')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'membership_id' => $employeeMembershipId,
            'organization_id' => $inactiveOrgId,
            'organization_unit_id' => null,
            'status' => 'INACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Milik operator SENDIRI (dari createOperatorAssignment di atas),
        // bukan employee ini — TIDAK boleh muncul. Tidak perlu insert
        // baru: baris $operatorAssignmentId sudah cukup jadi kasus uji
        // ini (constraint unik melarang baris kedua untuk kombinasi
        // membership+organization yang sama).

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route(
                    'api.v1.hr.workspace.employees.organizational-assignments.index',
                    ['employeeId' => $employeeId],
                    false,
                ),
            );

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(2, $data);

        $unitNames = collect($data)->pluck('organization_unit_name')->all();

        $this->assertContains('Unit Kurikulum', $unitNames);
        $this->assertContains(null, $unitNames);

        $organizationNames = collect($data)->pluck('organization_name')->unique()->all();

        $this->assertSame(['Induk'], $organizationNames);
    }

    public function test_returns_not_found_for_employee_outside_operators_workspace(): void
    {
        $employeeOrganizationId = UuidV7::generate();
        $this->createOrganizationFixture($employeeOrganizationId, 'Organisasi Pegawai');

        $employeeOperatorAssignmentId = $this->createOperatorAssignment($employeeOrganizationId);
        $this->grantScopedRole($employeeOperatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $employeeId = $this->createEmployee($employeeOperatorAssignmentId);

        $otherOrganizationId = UuidV7::generate();
        $this->createOrganizationFixture($otherOrganizationId, 'Organisasi Lain');
        $otherAssignmentId = $this->createOperatorAssignment($otherOrganizationId);
        $this->grantScopedRole($otherAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $otherAssignmentId,
            ])
            ->getJson(
                route(
                    'api.v1.hr.workspace.employees.organizational-assignments.index',
                    ['employeeId' => $employeeId],
                    false,
                ),
            );

        $response
            ->assertStatus(404)
            ->assertJsonPath('code', 'EMPLOYEE_NOT_FOUND');
    }

    private function createEmployee(string $operatorAssignmentId): string
    {
        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route('api.v1.hr.workspace.employees.store', [], false),
                [
                    'nama' => 'Pegawai Uji Organizational Assignment',
                    'nip' => 'NIP-OA-'.Str::upper(Str::random(6)),
                    'jabatan' => 'GURU',
                    'employment_type_id' => $this->employmentTypeId,
                ],
            );

        $response->assertCreated();

        return (string) $response->json('data.employee_id');
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
            'name' => 'Employee Organizational Assignments Tenant',
            'subdomain' => sprintf(
                'employee-org-assignments-%s',
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
            'name' => 'Employee Organizational Assignments Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'employee-org-assignments-operator-%s@educore.test',
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

    private function createOrganizationFixture(
        string $organizationId,
        string $name,
    ): void {
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createEmploymentType(): string
    {
        $employmentTypeId = UuidV7::generate();

        DB::table('employment_types')->insert([
            'id' => $employmentTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'TETAP-'.Str::upper(Str::random(6)),
            'name' => 'Pegawai Tetap',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentTypeId;
    }

    private function createOperatorAssignment(string $organizationId): string
    {
        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->operatorMembershipId,
            'organization_id' => $organizationId,
            'organization_unit_id' => null,
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
