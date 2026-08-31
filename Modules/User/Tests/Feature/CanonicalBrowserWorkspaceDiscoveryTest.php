<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

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
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class CanonicalBrowserWorkspaceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $userAId;

    private string $personAId;

    private string $userBId;

    private string $personBId;

    private string $tenantAId;

    private string $tenantBId;

    private string $membershipAId;

    private string $membershipBId;

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

        $this->userAId = UuidV7::generate();
        $this->personAId = UuidV7::generate();
        $this->userBId = UuidV7::generate();
        $this->personBId = UuidV7::generate();
        $this->tenantAId = UuidV7::generate();
        $this->tenantBId = UuidV7::generate();
        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();
        $this->emailA = sprintf(
            'canonical-browser-workspaces-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createFixture();
    }

    public function test_canonical_workspace_discovery_accepts_browser_session_for_selected_membership_without_exposing_bearer(): void
    {
        $bearerCredential = $this->loginBrowserMembershipContextAndAttachCookie();

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->getJson('/api/v1/user/my-workspaces');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'tenant' => [
                        'id' => $this->tenantAId,
                        'name' => 'Canonical Workspace Tenant A',
                    ],
                    'workspaces' => [
                        [
                            'type' => 'TENANT',
                            'organizational_assignment_id' => null,
                            'organization_id' => null,
                            'organization_unit_id' => null,
                            'label' => 'Canonical Workspace Tenant A',
                        ],
                    ],
                ],
            ]);

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_browser_workspace_discovery_ignores_browser_supplied_authorization_header(): void
    {
        $this->loginBrowserMembershipContextAndAttachCookie();

        $forgedBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userBId,
                $this->tenantBId,
                $this->membershipBId,
            );

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipAId,
            )
            ->withHeader(
                'Authorization',
                'Bearer '.$forgedBearer,
            )
            ->getJson('/api/v1/user/my-workspaces')
            ->assertOk()
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantAId,
            )
            ->assertJsonFragment([
                'type' => 'TENANT',
                'label' => 'Canonical Workspace Tenant A',
            ])
            ->assertJsonMissing([
                'id' => $this->tenantBId,
                'name' => 'Canonical Workspace Tenant B',
            ]);
    }

    public function test_canonical_browser_workspace_discovery_requires_membership_locator(): void
    {
        $this->loginBrowserMembershipContextAndAttachCookie();

        $this
            ->getJson('/api/v1/user/my-workspaces')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                'message' => 'Browser membership context is required.',
            ]);
    }

    public function test_canonical_browser_workspace_discovery_does_not_create_unknown_membership_context(): void
    {
        $this->loginBrowserMembershipContextAndAttachCookie();

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                UuidV7::generate(),
            )
            ->getJson('/api/v1/user/my-workspaces')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_MEMBERSHIP_CONTEXT_DENIED',
                'message' => 'Browser membership context is not available in this session.',
            ]);
    }

    public function test_browser_session_cookie_takes_precedence_over_bearer_for_workspace_discovery(): void
    {
        $bearerCredential = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userAId,
                $this->tenantAId,
                $this->membershipAId,
            );

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
                'Bearer '.$bearerCredential,
            )
            ->getJson('/api/v1/user/my-workspaces')
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                'message' => 'Authenticated browser session is required.',
            ]);
    }

    public function test_canonical_workspace_discovery_keeps_bearer_transport_stateless(): void
    {
        $bearerCredential = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userAId,
                $this->tenantAId,
                $this->membershipAId,
            );

        $this
            ->withToken($bearerCredential)
            ->getJson('/api/v1/user/my-workspaces')
            ->assertOk()
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantAId,
            )
            ->assertCookieMissing(
                $this->sessionCookieName(),
            );
    }

    public function test_canonical_workspace_discovery_route_uses_tenant_dual_transport_without_web_group(): void
    {
        $route = Route::getRoutes()->getByName(
            'api.v1.user.workspaces.index',
        );

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

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

        $transportIndex = array_search(
            UseBrowserSessionForCanonicalApi::class,
            $middleware,
            true,
        );
        $tenantContextIndex = array_search(
            InjectTransportAwareTenantContext::class,
            $middleware,
            true,
        );

        $this->assertIsInt($transportIndex);
        $this->assertIsInt($tenantContextIndex);
        $this->assertLessThan(
            $tenantContextIndex,
            $transportIndex,
            'BrowserSession activation must run before transport-aware tenant context resolution.',
        );
    }

    private function loginBrowserMembershipContextAndAttachCookie(): string
    {
        /*
         * Browser authentication establishes global Identity Context only.
         */
        $this
            ->postJson(
                '/api/v1/browser/auth/login',
                [
                    'identifier' => $this->emailA,
                    'password' => 'secret123',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.context_type',
                'identity',
            )
            ->assertJsonPath(
                'data.user.id',
                $this->userAId,
            );

        $browserAuthState = $this->app[
            'session'
        ]->get(
            'educore.browser_auth',
        );

        $this->assertIsArray(
            $browserAuthState,
        );

        $this->assertSame(
            $this->userAId,
            $browserAuthState['user_id']
                ?? null,
        );

        $this->assertSame(
            [],
            $browserAuthState[
                'membership_credentials'
            ] ?? null,
            'Fresh Browser login must not establish Workspace Membership context.',
        );

        /*
         * Workspace discovery is Membership/Tenant-scoped.
         *
         * Prepare the selected Membership credential explicitly through the
         * canonical Browser switch boundary.
         */
        $this
            ->postJson(
                sprintf(
                    '/api/v1/browser/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            )
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'membership_id' =>
                        $this->membershipAId,
                    'tenant_id' =>
                        $this->tenantAId,
                    'tenant_name' =>
                        'Canonical Workspace Tenant A',
                ],
            ]);

        $browserAuthState = $this->app[
            'session'
        ]->get(
            'educore.browser_auth',
        );

        $this->assertIsArray(
            $browserAuthState,
        );

        $bearerCredential = $browserAuthState[
            'membership_credentials'
        ][$this->membershipAId] ?? null;

        $this->assertIsString(
            $bearerCredential,
        );

        $this->assertNotSame(
            '',
            trim(
                $bearerCredential,
            ),
        );

        $claims = $this->app
            ->make(
                TokenManagerInterface::class,
            )
            ->validateAndExtract(
                $bearerCredential,
            );

        $this->assertIsArray(
            $claims,
        );

        $this->assertSame(
            'membership',
            $claims['credential_type']
                ?? null,
        );

        $this->assertSame(
            $this->userAId,
            $claims['user_id']
                ?? null,
        );

        $this->assertSame(
            $this->tenantAId,
            $claims['tenant_id']
                ?? null,
        );

        $this->assertSame(
            $this->membershipAId,
            $claims['membership_id']
                ?? null,
        );

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app[
                    'session'
                ]->getId(),
            );

        return $bearerCredential;
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
            [
                'id' => $this->personAId,
                'name' => 'Canonical Workspace User A',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->personBId,
                'name' => 'Canonical Workspace User B',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            [
                'id' => $this->userAId,
                'person_id' => $this->personAId,
                'email' => $this->emailA,
                'password' => bcrypt('secret123'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->userBId,
                'person_id' => $this->personBId,
                'email' => sprintf(
                    'canonical-browser-workspaces-other-%s@educore.test',
                    Str::lower(Str::random(10)),
                ),
                'password' => bcrypt('secret123'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('tenants')->insert([
            $this->tenantData(
                $this->tenantAId,
                'Canonical Workspace Tenant A',
                'canonical-workspace-a',
            ),
            $this->tenantData(
                $this->tenantBId,
                'Canonical Workspace Tenant B',
                'canonical-workspace-b',
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
}
