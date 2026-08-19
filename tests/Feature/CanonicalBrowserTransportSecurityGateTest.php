<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class CanonicalBrowserTransportSecurityGateTest extends TestCase
{
    use RefreshDatabase;

    private string $personAId;

    private string $personBId;

    private string $userAId;

    private string $userBId;

    private string $tenantAId;

    private string $tenantBId;

    private string $tenantCId;

    private string $membershipAId;

    private string $membershipBId;

    private string $membershipCId;

    private string $organizationAId;

    private string $organizationBId;

    private string $assignmentAId;

    private string $assignmentBId;

    private string $tenantRoleAId;

    private string $tenantRoleBId;

    private string $workspaceRoleAId;

    private string $workspaceRoleBId;

    private string $tenantPermissionAId;

    private string $tenantPermissionBId;

    private string $workspacePermissionAId;

    private string $workspacePermissionBId;

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

        $this->personAId = UuidV7::generate();
        $this->personBId = UuidV7::generate();
        $this->userAId = UuidV7::generate();
        $this->userBId = UuidV7::generate();
        $this->tenantAId = UuidV7::generate();
        $this->tenantBId = UuidV7::generate();
        $this->tenantCId = UuidV7::generate();
        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();
        $this->membershipCId = UuidV7::generate();
        $this->organizationAId = UuidV7::generate();
        $this->organizationBId = UuidV7::generate();
        $this->assignmentAId = UuidV7::generate();
        $this->assignmentBId = UuidV7::generate();
        $this->tenantRoleAId = UuidV7::generate();
        $this->tenantRoleBId = UuidV7::generate();
        $this->workspaceRoleAId = UuidV7::generate();
        $this->workspaceRoleBId = UuidV7::generate();
        $this->tenantPermissionAId = UuidV7::generate();
        $this->tenantPermissionBId = UuidV7::generate();
        $this->workspacePermissionAId = UuidV7::generate();
        $this->workspacePermissionBId = UuidV7::generate();
        $this->emailA = sprintf(
            'canonical-browser-security-gate-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createFixture();
    }

    public function test_browser_session_completes_canonical_bootstrap_for_two_memberships_without_global_active_context(): void
    {
        [$bearerA, $bearerB] = $this->loginAndPrepareBothMembershipCredentials();

        $this->assertCanonicalContext(
            membershipId: $this->membershipAId,
            tenantId: $this->tenantAId,
            bearers: [$bearerA, $bearerB],
        );
        $this->assertCanonicalContext(
            membershipId: $this->membershipBId,
            tenantId: $this->tenantBId,
            bearers: [$bearerA, $bearerB],
        );

        $membershipResponse = $this->getJson(
            '/api/v1/user/my-memberships',
        );

        $membershipResponse
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'membership_id' => $this->membershipAId,
                'tenant_id' => $this->tenantAId,
            ])
            ->assertJsonFragment([
                'membership_id' => $this->membershipBId,
                'tenant_id' => $this->tenantBId,
            ]);

        $this->assertNoBearerExposure(
            $membershipResponse,
            [$bearerA, $bearerB],
        );

        $this->assertCanonicalWorkspaceDiscovery(
            membershipId: $this->membershipAId,
            tenantId: $this->tenantAId,
            assignmentId: $this->assignmentAId,
            organizationId: $this->organizationAId,
            organizationLabel: 'Security Gate Organization A',
            bearers: [$bearerA, $bearerB],
        );
        $this->assertCanonicalWorkspaceDiscovery(
            membershipId: $this->membershipBId,
            tenantId: $this->tenantBId,
            assignmentId: $this->assignmentBId,
            organizationId: $this->organizationBId,
            organizationLabel: 'Security Gate Organization B',
            bearers: [$bearerA, $bearerB],
        );

        $this->assertCanonicalTenantCapabilities(
            membershipId: $this->membershipAId,
            tenantId: $this->tenantAId,
            expectedPermission: 'academic.grades.read',
            unexpectedPermission: 'dormitory.rooms.view',
            bearers: [$bearerA, $bearerB],
        );
        $this->assertCanonicalTenantCapabilities(
            membershipId: $this->membershipBId,
            tenantId: $this->tenantBId,
            expectedPermission: 'dormitory.rooms.view',
            unexpectedPermission: 'academic.grades.read',
            bearers: [$bearerA, $bearerB],
        );

        $this->assertCanonicalWorkspaceCapabilities(
            membershipId: $this->membershipAId,
            tenantId: $this->tenantAId,
            assignmentId: $this->assignmentAId,
            organizationId: $this->organizationAId,
            expectedTenantPermission: 'academic.grades.read',
            expectedWorkspacePermission: 'academic.grades.write',
            unexpectedPermission: 'dormitory.rooms.manage',
            bearers: [$bearerA, $bearerB],
        );
        $this->assertCanonicalWorkspaceCapabilities(
            membershipId: $this->membershipBId,
            tenantId: $this->tenantBId,
            assignmentId: $this->assignmentBId,
            organizationId: $this->organizationBId,
            expectedTenantPermission: 'dormitory.rooms.view',
            expectedWorkspacePermission: 'dormitory.rooms.manage',
            unexpectedPermission: 'academic.grades.write',
            bearers: [$bearerA, $bearerB],
        );

        $this->assertNull(session('active_membership_id'));
        $this->assertNull(session('active_tenant_id'));
    }

    public function test_forged_bearer_cannot_override_browser_selected_context_across_canonical_resources(): void
    {
        [$bearerA] = $this->loginAndPrepareBothMembershipCredentials();

        $forgedBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userBId,
                $this->tenantCId,
                [
                    'membership_id' => $this->membershipCId,
                ],
            );

        $this->withHeader(
            'Authorization',
            'Bearer '.$forgedBearer,
        );

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantAId,
            );

        $this
            ->getJson('/api/v1/user/my-memberships')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing([
                'membership_id' => $this->membershipCId,
            ]);

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->getJson('/api/v1/user/my-workspaces')
            ->assertOk()
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantAId,
            );

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->getJson('/api/v1/core/authorization/capabilities')
            ->assertOk()
            ->assertJsonPath(
                'data.scope.membership_id',
                $this->membershipAId,
            );

        $workspaceResponse = $this
            ->withHeaders([
                InjectBrowserTenantContext::HEADER => $this->membershipAId,
                InjectOrganizationalContext::HEADER => $this->assignmentAId,
            ])
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            );

        $workspaceResponse
            ->assertOk()
            ->assertJsonPath(
                'data.scope.membership_id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'data.scope.organizational_assignment_id',
                $this->assignmentAId,
            );

        $this->assertNoBearerExposure(
            $workspaceResponse,
            [$bearerA, $forgedBearer],
        );
    }

    public function test_bearer_only_canonical_resources_remain_stateless_without_browser_cookie(): void
    {
        $bearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
                [
                    'membership_id' => $this->membershipAId,
                ],
            );

        foreach (
            [
                $this->withToken($bearer)
                    ->getJson('/api/v1/auth/me'),
                $this->withToken($bearer)
                    ->getJson('/api/v1/user/my-memberships'),
                $this->withToken($bearer)
                    ->getJson('/api/v1/user/my-workspaces'),
                $this->withToken($bearer)
                    ->getJson('/api/v1/core/authorization/capabilities'),
                $this->withToken($bearer)
                    ->withHeader(
                        InjectOrganizationalContext::HEADER,
                        $this->assignmentAId,
                    )
                    ->getJson(
                        '/api/v1/core/authorization/workspace-capabilities',
                    ),
            ] as $response
        ) {
            $response
                ->assertOk()
                ->assertCookieMissing(
                    $this->sessionCookieName(),
                );
        }
    }

    public function test_browser_route_inventory_allows_only_control_plane_endpoints_and_transitional_auth_me_alias(): void
    {
        $browserRoutes = collect(
            Route::getRoutes()->getRoutes(),
        )
            ->filter(
                static fn (IlluminateRoute $route): bool => str_starts_with(
                    $route->uri(),
                    'api/v1/browser/',
                ),
            )
            ->mapWithKeys(
                static fn (IlluminateRoute $route): array => [
                    (string) $route->getName() => $route->uri(),
                ],
            )
            ->sortKeys()
            ->all();

        $this->assertSame(
            [
                'api.v1.browser.auth.login' => 'api/v1/browser/auth/login',
                'api.v1.browser.auth.logout' => 'api/v1/browser/auth/logout',
                'api.v1.browser.auth.me' => 'api/v1/browser/auth/me',
                'api.v1.browser.session.csrf' => 'api/v1/browser/session/csrf',
                'api.v1.browser.user.memberships.switch' => 'api/v1/browser/user/memberships/{membership_id}/switch',
            ],
            $browserRoutes,
        );

        foreach (
            [
                'api/v1/browser/user/my-memberships',
                'api/v1/browser/user/my-workspaces',
                'api/v1/browser/core/authorization/capabilities',
                'api/v1/browser/core/authorization/workspace-capabilities',
            ] as $forbiddenMirroredResource
        ) {
            $this->assertNotContains(
                $forbiddenMirroredResource,
                $browserRoutes,
                'Protected resource APIs must remain canonical instead of growing mirrored Browser BFF endpoints.',
            );
        }
    }

    /** @return array{0: string, 1: string} */
    private function loginAndPrepareBothMembershipCredentials(): array
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'email' => $this->emailA,
                'password' => 'secret123',
                'tenant_uuid' => $this->tenantAId,
            ],
        )->assertOk();

        $stateBeforeSwitch = $this->browserAuthState();
        $bearerA = $stateBeforeSwitch[
            'membership_credentials'
        ][$this->membershipAId] ?? null;

        $this->assertIsString($bearerA);
        $this->assertNotSame('', trim($bearerA));

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app['session']->getId(),
            );

        $switchResponse = $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $this->membershipBId,
            ),
        );

        $switchResponse
            ->assertOk()
            ->assertJsonPath(
                'data.membership_id',
                $this->membershipBId,
            )
            ->assertJsonPath(
                'data.tenant_id',
                $this->tenantBId,
            );

        $stateAfterSwitch = $this->browserAuthState();
        $bearerB = $stateAfterSwitch[
            'membership_credentials'
        ][$this->membershipBId] ?? null;

        $this->assertIsString($bearerB);
        $this->assertNotSame('', trim($bearerB));
        $this->assertSame(
            $bearerA,
            $stateAfterSwitch[
                'membership_credentials'
            ][$this->membershipAId] ?? null,
        );

        $this->assertNoBearerExposure(
            $switchResponse,
            [$bearerA, $bearerB],
        );

        return [$bearerA, $bearerB];
    }

    /** @return array<string, mixed> */
    private function browserAuthState(): array
    {
        $state = $this->app['session']->get(
            'educore.browser_auth',
        );

        $this->assertIsArray($state);

        return $state;
    }

    /** @param list<string> $bearers */
    private function assertCanonicalContext(
        string $membershipId,
        string $tenantId,
        array $bearers,
    ): void {
        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $membershipId,
            )
            ->getJson('/api/v1/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.user.id',
                $this->userAId,
            )
            ->assertJsonPath(
                'data.membership.id',
                $membershipId,
            )
            ->assertJsonPath(
                'data.tenant.id',
                $tenantId,
            );

        $this->assertNoBearerExposure(
            $response,
            $bearers,
        );
    }

    /** @param list<string> $bearers */
    private function assertCanonicalWorkspaceDiscovery(
        string $membershipId,
        string $tenantId,
        string $assignmentId,
        string $organizationId,
        string $organizationLabel,
        array $bearers,
    ): void {
        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $membershipId,
            )
            ->getJson('/api/v1/user/my-workspaces');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.tenant.id',
                $tenantId,
            )
            ->assertJsonFragment([
                'type' => 'ORGANIZATION',
                'organizational_assignment_id' => $assignmentId,
                'organization_id' => $organizationId,
                'organization_unit_id' => null,
                'label' => $organizationLabel,
            ]);

        $this->assertNoBearerExposure(
            $response,
            $bearers,
        );
    }

    /** @param list<string> $bearers */
    private function assertCanonicalTenantCapabilities(
        string $membershipId,
        string $tenantId,
        string $expectedPermission,
        string $unexpectedPermission,
        array $bearers,
    ): void {
        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $membershipId,
            )
            ->getJson('/api/v1/core/authorization/capabilities');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.scope.tenant_id',
                $tenantId,
            )
            ->assertJsonPath(
                'data.scope.membership_id',
                $membershipId,
            );

        $permissions = $response->json('data.permissions');

        $this->assertIsArray($permissions);
        $this->assertContains(
            $expectedPermission,
            $permissions,
        );
        $this->assertNotContains(
            $unexpectedPermission,
            $permissions,
        );

        $this->assertNoBearerExposure(
            $response,
            $bearers,
        );
    }

    /** @param list<string> $bearers */
    private function assertCanonicalWorkspaceCapabilities(
        string $membershipId,
        string $tenantId,
        string $assignmentId,
        string $organizationId,
        string $expectedTenantPermission,
        string $expectedWorkspacePermission,
        string $unexpectedPermission,
        array $bearers,
    ): void {
        $response = $this
            ->withHeaders([
                InjectBrowserTenantContext::HEADER => $membershipId,
                InjectOrganizationalContext::HEADER => $assignmentId,
            ])
            ->getJson(
                '/api/v1/core/authorization/workspace-capabilities',
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.scope.tenant_id',
                $tenantId,
            )
            ->assertJsonPath(
                'data.scope.membership_id',
                $membershipId,
            )
            ->assertJsonPath(
                'data.scope.organizational_assignment_id',
                $assignmentId,
            )
            ->assertJsonPath(
                'data.scope.organization_id',
                $organizationId,
            );

        $permissions = $response->json('data.permissions');

        $this->assertIsArray($permissions);
        $this->assertContains(
            $expectedTenantPermission,
            $permissions,
        );
        $this->assertContains(
            $expectedWorkspacePermission,
            $permissions,
        );
        $this->assertNotContains(
            $unexpectedPermission,
            $permissions,
        );

        $this->assertNoBearerExposure(
            $response,
            $bearers,
        );
    }

    /** @param list<string> $bearers */
    private function assertNoBearerExposure(
        TestResponse $response,
        array $bearers,
    ): void {
        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'token_type',
            $response->getContent(),
        );

        foreach ($bearers as $bearer) {
            $this->assertStringNotContainsString(
                $bearer,
                $response->getContent(),
            );
        }
    }

    private function sessionCookieName(): string
    {
        $cookieName = config('session.cookie');

        $this->assertIsString($cookieName);
        $this->assertNotSame('', trim($cookieName));

        return $cookieName;
    }

    private function createFixture(): void
    {
        DB::table('persons')->insert([
            $this->personData(
                $this->personAId,
                'Security Gate User A',
            ),
            $this->personData(
                $this->personBId,
                'Security Gate User B',
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
                    'security-gate-other-%s@educore.test',
                    Str::lower(Str::random(10)),
                ),
            ),
        ]);

        DB::table('tenants')->insert([
            $this->tenantData(
                $this->tenantAId,
                'Security Gate Tenant A',
                'security-gate-a',
            ),
            $this->tenantData(
                $this->tenantBId,
                'Security Gate Tenant B',
                'security-gate-b',
            ),
            $this->tenantData(
                $this->tenantCId,
                'Security Gate Tenant C',
                'security-gate-c',
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
                $this->personAId,
                $this->tenantBId,
            ),
            $this->membershipData(
                $this->membershipCId,
                $this->personBId,
                $this->tenantCId,
            ),
        ]);

        DB::table('organizations')->insert([
            $this->organizationData(
                $this->organizationAId,
                $this->tenantAId,
                'Security Gate Organization A',
                'SEC-GATE-A',
            ),
            $this->organizationData(
                $this->organizationBId,
                $this->tenantBId,
                'Security Gate Organization B',
                'SEC-GATE-B',
            ),
        ]);

        DB::table('organizational_assignments')->insert([
            $this->assignmentData(
                $this->assignmentAId,
                $this->tenantAId,
                $this->membershipAId,
                $this->organizationAId,
            ),
            $this->assignmentData(
                $this->assignmentBId,
                $this->tenantBId,
                $this->membershipBId,
                $this->organizationBId,
            ),
        ]);

        DB::table('roles')->insert([
            $this->roleData(
                $this->tenantRoleAId,
                'security-gate-tenant-a',
                'Security Gate Tenant Role A',
            ),
            $this->roleData(
                $this->tenantRoleBId,
                'security-gate-tenant-b',
                'Security Gate Tenant Role B',
            ),
            $this->roleData(
                $this->workspaceRoleAId,
                'security-gate-workspace-a',
                'Security Gate Workspace Role A',
            ),
            $this->roleData(
                $this->workspaceRoleBId,
                'security-gate-workspace-b',
                'Security Gate Workspace Role B',
            ),
        ]);

        DB::table('permissions')->insert([
            $this->permissionData(
                $this->tenantPermissionAId,
                'academic.grades.read',
                'Read Academic Grades',
                'Academic',
            ),
            $this->permissionData(
                $this->tenantPermissionBId,
                'dormitory.rooms.view',
                'View Dormitory Rooms',
                'Dormitory',
            ),
            $this->permissionData(
                $this->workspacePermissionAId,
                'academic.grades.write',
                'Write Academic Grades',
                'Academic',
            ),
            $this->permissionData(
                $this->workspacePermissionBId,
                'dormitory.rooms.manage',
                'Manage Dormitory Rooms',
                'Dormitory',
            ),
        ]);

        DB::table('role_permissions')->insert([
            [
                'role_id' => $this->tenantRoleAId,
                'permission_id' => $this->tenantPermissionAId,
            ],
            [
                'role_id' => $this->tenantRoleBId,
                'permission_id' => $this->tenantPermissionBId,
            ],
            [
                'role_id' => $this->workspaceRoleAId,
                'permission_id' => $this->workspacePermissionAId,
            ],
            [
                'role_id' => $this->workspaceRoleBId,
                'permission_id' => $this->workspacePermissionBId,
            ],
        ]);

        DB::table('membership_roles')->insert([
            [
                'membership_id' => $this->membershipAId,
                'role_id' => $this->tenantRoleAId,
            ],
            [
                'membership_id' => $this->membershipBId,
                'role_id' => $this->tenantRoleBId,
            ],
        ]);

        DB::table('organizational_assignment_roles')->insert([
            [
                'organizational_assignment_id' => $this->assignmentAId,
                'role_id' => $this->workspaceRoleAId,
            ],
            [
                'organizational_assignment_id' => $this->assignmentBId,
                'role_id' => $this->workspaceRoleBId,
            ],
        ]);
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
    private function organizationData(
        string $organizationId,
        string $tenantId,
        string $name,
        string $code,
    ): array {
        return [
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentData(
        string $assignmentId,
        string $tenantId,
        string $membershipId,
        string $organizationId,
    ): array {
        return [
            'id' => $assignmentId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'organization_unit_id' => null,
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
