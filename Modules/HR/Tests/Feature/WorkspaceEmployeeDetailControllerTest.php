<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class WorkspaceEmployeeDetailControllerTest extends TestCase
{
    use GrantsSubscriptionFeature;
    use RefreshDatabase;

    private string $tenantId;

    private string $operatorUserId;

    private string $operatorMembershipId;

    private string $organizationId;

    private string $operatorEmail;

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
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_show_returns_employee_detail_with_employment_history(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $employeeId = $this->createEmployeeWithOpenPlacement($this->organizationId);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route('api.v1.hr.workspace.employees.show', ['employeeId' => $employeeId], false),
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $employeeId)
            ->assertJsonPath('data.nama', 'Workspace Employee Detail Fixture Employee')
            ->assertJsonPath('data.employments.0.status', 'ACTIVE');
    }

    public function test_show_returns_not_found_when_employee_is_outside_current_workspace(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $otherOrganizationId = $this->createOrganizationFixture(UuidV7::generate());
        $outOfScopeEmployeeId = $this->createEmployeeWithOpenPlacement($otherOrganizationId);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route('api.v1.hr.workspace.employees.show', ['employeeId' => $outOfScopeEmployeeId], false),
            );

        $response->assertNotFound();
    }

    public function test_show_returns_not_found_for_unknown_employee_id(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route('api.v1.hr.workspace.employees.show', ['employeeId' => UuidV7::generate()], false),
            );

        $response->assertNotFound();
    }

    public function test_show_accepts_browser_session_without_exposing_bearer(): void
    {
        config(['session.driver' => 'array']);

        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $employeeId = $this->createEmployeeWithOpenPlacement($this->organizationId);

        $bearerCredential = $this->loginBrowserSessionAndAttachCookie();

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->operatorMembershipId,
            )
            ->withHeader(
                InjectOrganizationalContext::HEADER,
                $operatorAssignmentId,
            )
            ->getJson(
                route('api.v1.hr.workspace.employees.show', ['employeeId' => $employeeId], false),
            );

        $response->assertOk();

        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_show_requires_organizational_context_header(): void
    {
        $employeeId = $this->createEmployeeWithOpenPlacement($this->organizationId);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.workspace.employees.show', ['employeeId' => $employeeId], false),
            );

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'ORGANIZATIONAL_CONTEXT_REQUIRED');
    }

    public function test_show_is_denied_without_scoped_permission_grant(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        // Sengaja TIDAK memanggil grantScopedRole().

        $employeeId = $this->createEmployeeWithOpenPlacement($this->organizationId);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route('api.v1.hr.workspace.employees.show', ['employeeId' => $employeeId], false),
            );

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
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

    private function loginBrowserSessionAndAttachCookie(): string
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'identifier' => $this->operatorEmail,
                'password' => 'secret123',
            ],
        )->assertOk();

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app['session']->getId(),
            );

        $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $this->operatorMembershipId,
            ),
        )->assertOk();

        $browserAuthState = $this->app['session']->get(
            'educore.browser_auth',
        );

        $this->assertIsArray($browserAuthState);

        $bearerCredential = $browserAuthState['membership_credentials'][$this->operatorMembershipId] ?? null;

        $this->assertIsString($bearerCredential);
        $this->assertNotSame('', trim($bearerCredential));

        return $bearerCredential;
    }

    private function sessionCookieName(): string
    {
        $cookieName = config('session.cookie');

        $this->assertIsString($cookieName);
        $this->assertNotSame('', trim($cookieName));

        return $cookieName;
    }

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Workspace Employee Detail Tenant',
            'subdomain' => sprintf(
                'workspace-emp-detail-%s',
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
            'name' => 'Workspace Employee Detail Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->operatorEmail = sprintf(
            'workspace-emp-detail-operator-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => $this->operatorEmail,
            'password' => bcrypt('secret123'),
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
            'name' => 'Workspace Employee Detail Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createOperatorAssignment(string $organizationId): string
    {
        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->operatorMembershipId,
            'organization_id' => $organizationId,
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

    private function createEmployeeWithOpenPlacement(
        string $organizationId,
    ): string {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Workspace Employee Detail Fixture Employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $this->tenantId,
            'employee_id' => $employeeId,
            'status' => 'ACTIVE',
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employment_placements')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2026-01-01',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employeeId;
    }
}
