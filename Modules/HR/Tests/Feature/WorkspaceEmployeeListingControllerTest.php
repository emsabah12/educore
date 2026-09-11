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

final class WorkspaceEmployeeListingControllerTest extends TestCase
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

    public function test_workspace_listing_returns_only_employees_visible_in_current_workspace(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $this->createEmployeeWithOpenPlacement($this->organizationId);
        $this->createEmployeeWithOpenPlacement($this->organizationId);

        $otherOrganizationId = $this->createOrganizationFixture(UuidV7::generate());
        $this->createEmployeeWithOpenPlacement($otherOrganizationId);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(route('api.v1.hr.workspace.employees.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    /**
     * INV-HR-011 — memperbesar per_page tidak pernah membocorkan Employee
     * di luar workspace, sekalipun jumlahnya melebihi total data workspace.
     */
    public function test_workspace_listing_includes_employee_name(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $this->createEmployeeWithOpenPlacement($this->organizationId);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(route('api.v1.hr.workspace.employees.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.nama', 'Workspace Employee Listing Fixture Employee');
    }

    public function test_workspace_listing_accepts_browser_session_without_exposing_bearer(): void
    {
        config(['session.driver' => 'array']);

        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $this->createEmployeeWithOpenPlacement($this->organizationId);

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
            ->getJson(route('api.v1.hr.workspace.employees.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_workspace_listing_respects_page_query_parameter(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $firstEmployeeId = $this->createEmployeeWithOpenPlacement($this->organizationId);
        $secondEmployeeId = $this->createEmployeeWithOpenPlacement($this->organizationId);

        $secondPageResponse = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route('api.v1.hr.workspace.employees.index', [], false).'?per_page=1&page=2',
            );

        $secondPageResponse
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');

        $secondPageEmployeeId = $secondPageResponse->json('data.0.id');

        $this->assertContains(
            $secondPageEmployeeId,
            [$firstEmployeeId, $secondEmployeeId],
        );

        $firstPageResponse = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route('api.v1.hr.workspace.employees.index', [], false).'?per_page=1&page=1',
            );

        $firstPageResponse->assertOk();

        $this->assertNotSame(
            $firstPageResponse->json('data.0.id'),
            $secondPageEmployeeId,
        );
    }

    public function test_workspace_listing_never_leaks_beyond_workspace_even_with_large_per_page(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $this->createEmployeeWithOpenPlacement($this->organizationId);

        $otherOrganizationId = $this->createOrganizationFixture(UuidV7::generate());
        for ($i = 0; $i < 5; $i++) {
            $this->createEmployeeWithOpenPlacement($otherOrganizationId);
        }

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(
                route('api.v1.hr.workspace.employees.index', [], false).'?per_page=100',
            );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_workspace_listing_is_empty_when_no_employees_in_workspace(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(route('api.v1.hr.workspace.employees.index', [], false));

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_workspace_listing_requires_organizational_context_header(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.hr.workspace.employees.index', [], false));

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'ORGANIZATIONAL_CONTEXT_REQUIRED');
    }

    public function test_workspace_listing_is_denied_without_scoped_permission_grant(): void
    {
        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        // Sengaja TIDAK memanggil grantScopedRole().

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(route('api.v1.hr.workspace.employees.index', [], false));

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
    }

    public function test_workspace_listing_is_denied_when_tenant_lacks_hr_module_feature(): void
    {
        // setUp() grants hr_module — remove it to prove the
        // tenant.feature:hr_module gate is actually enforced, not just
        // structurally present in the route middleware array.
        DB::table('tenant_subscriptions')
            ->where('tenant_id', $this->tenantId)
            ->delete();

        $operatorAssignmentId = $this->createOperatorAssignment($this->organizationId);
        $this->grantScopedRole($operatorAssignmentId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->withHeaders([
                InjectOrganizationalContext::HEADER => $operatorAssignmentId,
            ])
            ->getJson(route('api.v1.hr.workspace.employees.index', [], false));

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'SUBSCRIPTION_FEATURE_NOT_AVAILABLE');
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
            'name' => 'Workspace Employee Listing Tenant',
            'subdomain' => sprintf(
                'workspace-emp-list-%s',
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
            'name' => 'Workspace Employee Listing Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->operatorEmail = sprintf(
            'workspace-emp-list-operator-%s@educore.test',
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
            'name' => 'Workspace Employee Listing Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    /**
     * Assignment milik OPERATOR sendiri — dirujuk header
     * X-EduCore-Organizational-Assignment-Id untuk membentuk workspace.
     */
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
            'name' => 'Workspace Employee Listing Fixture Employee',
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
