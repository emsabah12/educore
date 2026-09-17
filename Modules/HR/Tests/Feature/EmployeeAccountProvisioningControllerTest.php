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
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class EmployeeAccountProvisioningControllerTest extends TestCase
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

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();
        $this->organizationId = UuidV7::generate();

        $this->createTenantFixture();
        $this->grantTenantFeature($this->tenantId, 'hr_module');
        $this->createOperatorFixture();
        $this->createOrganizationFixture($this->organizationId);
        $this->employmentTypeId = $this->createEmploymentType();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_account_succeeds_for_employee_without_existing_account(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $employeeId = $this->createEmployee($operatorAssignmentId);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route(
                    'api.v1.hr.workspace.employees.create-account',
                    ['employeeId' => $employeeId],
                    false,
                ),
                [
                    'email' => sprintf(
                        'pegawai-baru-%s@educore.test',
                        Str::lower(Str::random(8)),
                    ),
                ],
            );

        $response->assertCreated();

        $generatedPassword = $response->json('data.generated_password');
        $userId = $response->json('data.user_id');

        $this->assertNotEmpty($generatedPassword);
        $this->assertGreaterThanOrEqual(16, strlen((string) $generatedPassword));

        $this->assertDatabaseHas('users', [
            'id' => $userId,
        ]);

        // Password mentah TIDAK PERNAH tersimpan apa adanya — cast
        // 'hashed' pada model User wajib benar-benar menghasilkan hash,
        // bukan menyimpan plaintext.
        $storedHash = DB::table('users')
            ->where('id', $userId)
            ->value('password');

        $this->assertNotSame($generatedPassword, $storedHash);
    }

    public function test_create_account_rejects_when_person_already_has_an_account(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $employeeId = $this->createEmployee($operatorAssignmentId);

        $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route(
                    'api.v1.hr.workspace.employees.create-account',
                    ['employeeId' => $employeeId],
                    false,
                ),
                [
                    'email' => sprintf(
                        'pegawai-pertama-%s@educore.test',
                        Str::lower(Str::random(8)),
                    ),
                ],
            )
            ->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->postJson(
                route(
                    'api.v1.hr.workspace.employees.create-account',
                    ['employeeId' => $employeeId],
                    false,
                ),
                [
                    'email' => sprintf(
                        'pegawai-kedua-%s@educore.test',
                        Str::lower(Str::random(8)),
                    ),
                ],
            );

        $response
            ->assertStatus(409)
            ->assertJsonPath('code', 'EMPLOYEE_ACCOUNT_CONFLICT');
    }

    public function test_create_account_returns_not_found_for_employee_outside_operators_workspace(): void
    {
        $employeeOrganizationId = UuidV7::generate();
        $this->createOrganizationFixture($employeeOrganizationId);

        $employeeOperatorAssignmentId = $this->createOperatorAssignment($employeeOrganizationId);
        $this->grantScopedRole($employeeOperatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $employeeId = $this->createEmployee($employeeOperatorAssignmentId);

        // Operator KEDUA, di-scope ke organisasi LAIN (bukan tempat
        // Employee di atas dibuat).
        $otherOrganizationId = UuidV7::generate();
        $this->createOrganizationFixture($otherOrganizationId);
        $otherAssignmentId = $this->createOperatorAssignment($otherOrganizationId);
        $this->grantScopedRole($otherAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $otherAssignmentId,
            ])
            ->postJson(
                route(
                    'api.v1.hr.workspace.employees.create-account',
                    ['employeeId' => $employeeId],
                    false,
                ),
                [
                    'email' => sprintf(
                        'lintas-workspace-%s@educore.test',
                        Str::lower(Str::random(8)),
                    ),
                ],
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
                    'nama' => 'Pegawai Uji Akun Login',
                    'nip' => 'NIP-ACCT-'.Str::upper(Str::random(6)),
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
            'name' => 'Employee Account Provisioning Tenant',
            'subdomain' => sprintf(
                'employee-account-%s',
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
            'name' => 'Employee Account Provisioning Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'employee-account-operator-%s@educore.test',
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
            'name' => 'Employee Account Provisioning Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
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
