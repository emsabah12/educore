<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareTenantContext;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class CanonicalBrowserCapabilityProjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;

    private string $tenantBId;

    private string $personAId;

    private string $personBId;

    private string $userAId;

    private string $userBId;

    private string $membershipAId;

    private string $membershipBId;

    private string $organizationAId;

    private string $assignmentAId;

    private string $tenantRoleId;

    private string $workspaceRoleId;

    private string $tenantPermissionId;

    private string $workspacePermissionId;

    private string $deniedPermissionId;

    private string $emailA;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'array',
        ]);

        $this->app->instance(
            AuditTrailServiceInterface::class,
            $this->createStub(
                AuditTrailServiceInterface::class,
            ),
        );

        $this->tenantAId = UuidV7::generate();
        $this->tenantBId = UuidV7::generate();
        $this->personAId = UuidV7::generate();
        $this->personBId = UuidV7::generate();
        $this->userAId = UuidV7::generate();
        $this->userBId = UuidV7::generate();
        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();
        $this->organizationAId = UuidV7::generate();
        $this->assignmentAId = UuidV7::generate();
        $this->tenantRoleId = UuidV7::generate();
        $this->workspaceRoleId = UuidV7::generate();
        $this->tenantPermissionId = UuidV7::generate();
        $this->workspacePermissionId = UuidV7::generate();
        $this->deniedPermissionId = UuidV7::generate();
        $this->emailA = sprintf(
            'canonical-browser-capabilities-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createFixture();
    }

    public function test_canonical_tenant_capability_projection_accepts_browser_session_without_exposing_bearer(): void
    {
        $bearerCredential = $this->loginBrowserSessionAndAttachCookie();

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->getJson('/api/v1/core/authorization/capabilities');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.scope.type', 'tenant')
            ->assertJsonPath(
                'data.scope.tenant_id',
                $this->tenantAId,
            )
            ->assertJsonPath(
                'data.scope.membership_id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'data.permissions.0',
                'academic.grades.write',
            )
            ->assertJsonCount(1, 'data.permissions');

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_canonical_workspace_capability_projection_accepts_browser_session_and_organizational_locator(): void
    {
        $bearerCredential = $this->loginBrowserSessionAndAttachCookie();

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->withHeader(
                InjectOrganizationalContext::HEADER,
                $this->assignmentAId,
            )
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.scope.type', 'organization')
            ->assertJsonPath(
                'data.scope.tenant_id',
                $this->tenantAId,
            )
            ->assertJsonPath(
                'data.scope.membership_id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'data.scope.organizational_assignment_id',
                $this->assignmentAId,
            )
            ->assertJsonPath(
                'data.scope.organization_id',
                $this->organizationAId,
            )
            ->assertJsonPath(
                'data.permissions.0',
                'academic.grades.write',
            )
            ->assertJsonPath(
                'data.permissions.1',
                'dormitory.rooms.manage',
            )
            ->assertJsonCount(2, 'data.permissions');

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_browser_capability_projection_ignores_browser_supplied_authorization_header(): void
    {
        $this->loginBrowserSessionAndAttachCookie();

        $forgedBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userBId,
                $this->tenantBId,
                [
                    'membership_id' => $this->membershipBId,
                ],
            );

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->withHeader(
                'Authorization',
                'Bearer ' . $forgedBearer,
            )
            ->getJson('/api/v1/core/authorization/capabilities')
            ->assertOk()
            ->assertJsonPath(
                'data.scope.tenant_id',
                $this->tenantAId,
            )
            ->assertJsonPath(
                'data.scope.membership_id',
                $this->membershipAId,
            );
    }

    public function test_canonical_browser_tenant_capability_requires_membership_locator(): void
    {
        $this->loginBrowserSessionAndAttachCookie();

        $this
            ->getJson('/api/v1/core/authorization/capabilities')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                'message' => 'Browser membership context is required.',
            ]);
    }

    public function test_canonical_browser_workspace_capability_requires_organizational_locator_after_tenant_context(): void
    {
        $this->loginBrowserSessionAndAttachCookie();

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            )
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'ORGANIZATIONAL_CONTEXT_REQUIRED',
                'message' => 'Organizational workspace is required for this operation.',
            ]);
    }

    public function test_canonical_browser_workspace_capability_rejects_unknown_organizational_locator(): void
    {
        $this->loginBrowserSessionAndAttachCookie();

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->withHeader(
                InjectOrganizationalContext::HEADER,
                UuidV7::generate(),
            )
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            )
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'ORGANIZATIONAL_CONTEXT_DENIED',
            );
    }

    public function test_browser_session_cookie_takes_precedence_over_bearer_for_capability_projection(): void
    {
        $bearerCredential = $this->issueTokenForUserA();

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                Str::random(40),
            )
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->withHeader(
                'Authorization',
                'Bearer ' . $bearerCredential,
            )
            ->getJson('/api/v1/core/authorization/capabilities')
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                'message' => 'Authenticated browser session is required.',
            ]);
    }

    public function test_canonical_capability_projection_keeps_bearer_transport_stateless(): void
    {
        $bearerCredential = $this->issueTokenForUserA();

        $this
            ->withToken($bearerCredential)
            ->getJson('/api/v1/core/authorization/capabilities')
            ->assertOk()
            ->assertJsonPath(
                'data.scope.tenant_id',
                $this->tenantAId,
            )
            ->assertCookieMissing(
                $this->sessionCookieName(),
            );

        $this
            ->withToken($bearerCredential)
            ->withHeader(
                InjectOrganizationalContext::HEADER,
                $this->assignmentAId,
            )
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            )
            ->assertOk()
            ->assertJsonPath(
                'data.scope.organizational_assignment_id',
                $this->assignmentAId,
            )
            ->assertCookieMissing(
                $this->sessionCookieName(),
            );
    }

    public function test_canonical_capability_routes_use_expected_dual_transport_and_workspace_ordering(): void
    {
        $tenantRoute = Route::getRoutes()->getByName(
            'api.v1.core.authorization.capabilities.index',
        );
        $workspaceRoute = Route::getRoutes()->getByName(
            'api.v1.core.authorization.workspace-capabilities.index',
        );

        $this->assertNotNull($tenantRoute);
        $this->assertNotNull($workspaceRoute);

        $tenantMiddleware = $tenantRoute->gatherMiddleware();
        $workspaceMiddleware = $workspaceRoute->gatherMiddleware();

        foreach ([$tenantMiddleware, $workspaceMiddleware] as $middleware) {
            $this->assertContains('api', $middleware);
            $this->assertContains(
                UseBrowserSessionForCanonicalApi::class,
                $middleware,
            );
            $this->assertContains(
                InjectTransportAwareTenantContext::class,
                $middleware,
            );
            $this->assertNotContains('web', $middleware);
            $this->assertNotContains(
                InjectTenantContext::class,
                $middleware,
            );
        }

        $this->assertNotContains(
            InjectOrganizationalContext::class,
            $tenantMiddleware,
        );
        $this->assertContains(
            InjectOrganizationalContext::class,
            $workspaceMiddleware,
        );

        $browserIndex = array_search(
            UseBrowserSessionForCanonicalApi::class,
            $workspaceMiddleware,
            true,
        );
        $tenantIndex = array_search(
            InjectTransportAwareTenantContext::class,
            $workspaceMiddleware,
            true,
        );
        $organizationIndex = array_search(
            InjectOrganizationalContext::class,
            $workspaceMiddleware,
            true,
        );

        $this->assertIsInt($browserIndex);
        $this->assertIsInt($tenantIndex);
        $this->assertIsInt($organizationIndex);
        $this->assertLessThan($tenantIndex, $browserIndex);
        $this->assertLessThan($organizationIndex, $tenantIndex);
    }

    private function loginBrowserSessionAndAttachCookie(): string
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'identifier' => $this->emailA,
                'password' => 'secret123',
            ],
        )->assertOk();

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app['session']->getId(),
            );

        // Kontrak login global (identifier-only) tidak lagi memilih
        // Membership secara implisit. Membership A sekarang harus
        // diperoleh lewat switch eksplisit, bukan otomatis dari
        // payload login (lihat AuthTokenFlowTest).
        $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $this->membershipAId,
            ),
        )->assertOk();

        $browserAuthState = $this->app['session']->get(
            'educore.browser_auth',
        );

        $this->assertIsArray($browserAuthState);

        $bearerCredential = $browserAuthState['membership_credentials'][$this->membershipAId] ?? null;

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

    private function issueTokenForUserA(): string
    {
        return $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
                [
                    'membership_id' => $this->membershipAId,
                ],
            );
    }

    private function createFixture(): void
    {
        DB::table('tenants')->insert([
            $this->tenantData(
                $this->tenantAId,
                'Canonical Capability Tenant A',
                'canonical-capability-a',
            ),
            $this->tenantData(
                $this->tenantBId,
                'Canonical Capability Tenant B',
                'canonical-capability-b',
            ),
        ]);

        DB::table('persons')->insert([
            $this->personData(
                $this->personAId,
                'Canonical Capability User A',
            ),
            $this->personData(
                $this->personBId,
                'Canonical Capability User B',
            ),
        ]);

        DB::table('users')->insert([
            $this->userData(
                $this->userAId,
                $this->personAId,
                $this->emailA,
            ),
            $this->userData(
                $this->userBId,
                $this->personBId,
                sprintf(
                    'canonical-browser-capabilities-other-%s@educore.test',
                    Str::lower(Str::random(10)),
                ),
            ),
        ]);

        DB::table('memberships')->insert([
            $this->membershipData(
                $this->membershipAId,
                $this->personAId,
                $this->tenantAId,
            ),
            $this->membershipData(
                $this->membershipBId,
                $this->personBId,
                $this->tenantBId,
            ),
        ]);

        DB::table('organizations')->insert([
            'id' => $this->organizationAId,
            'tenant_id' => $this->tenantAId,
            'name' => 'Canonical Capability Organization A',
            'code' => 'CANONICAL-CAPABILITY-A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizational_assignments')->insert([
            'id' => $this->assignmentAId,
            'tenant_id' => $this->tenantAId,
            'membership_id' => $this->membershipAId,
            'organization_id' => $this->organizationAId,
            'organization_unit_id' => null,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('roles')->insert([
            $this->roleData(
                $this->tenantRoleId,
                'canonical-capability-tenant-role',
                'Canonical Capability Tenant Role',
            ),
            $this->roleData(
                $this->workspaceRoleId,
                'canonical-capability-workspace-role',
                'Canonical Capability Workspace Role',
            ),
        ]);

        DB::table('permissions')->insert([
            $this->permissionData(
                $this->tenantPermissionId,
                'academic.grades.write',
                'Write Academic Grades',
                'Academic',
            ),
            $this->permissionData(
                $this->workspacePermissionId,
                'dormitory.rooms.manage',
                'Manage Dormitory Rooms',
                'Dormitory',
            ),
            $this->permissionData(
                $this->deniedPermissionId,
                'dormitory.rooms.view',
                'View Dormitory Rooms',
                'Dormitory',
            ),
        ]);

        DB::table('role_permissions')->insert([
            [
                'role_id' => $this->tenantRoleId,
                'permission_id' => $this->tenantPermissionId,
            ],
            [
                'role_id' => $this->workspaceRoleId,
                'permission_id' => $this->workspacePermissionId,
            ],
        ]);

        DB::table('membership_roles')->insert([
            'membership_id' => $this->membershipAId,
            'role_id' => $this->tenantRoleId,
        ]);

        DB::table('organizational_assignment_roles')->insert([
            'organizational_assignment_id' => $this->assignmentAId,
            'role_id' => $this->workspaceRoleId,
        ]);
    }

    /** @return array<string, mixed> */
    private function tenantData(
        string $tenantId,
        string $name,
        string $subdomain,
    ): array {
        return [
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => $subdomain,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function personData(
        string $personId,
        string $name,
    ): array {
        return [
            'id' => $personId,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function userData(
        string $userId,
        string $personId,
        string $email,
    ): array {
        return [
            'id' => $userId,
            'person_id' => $personId,
            'email' => $email,
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function membershipData(
        string $membershipId,
        string $personId,
        string $tenantId,
    ): array {
        return [
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function roleData(
        string $roleId,
        string $name,
        string $displayName,
    ): array {
        return [
            'id' => $roleId,
            'name' => $name,
            'display_name' => $displayName,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function permissionData(
        string $permissionId,
        string $name,
        string $displayName,
        string $module,
    ): array {
        return [
            'id' => $permissionId,
            'name' => $name,
            'display_name' => $displayName,
            'description' => null,
            'module' => $module,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
