<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareTenantContext;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class CanonicalBrowserAuthenticatedContextTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $personId;

    private string $tenantId;

    private string $membershipId;

    private string $alternateTenantId;

    private string $alternateMembershipId;

    private string $email;

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

        $this->userId = UuidV7::generate();
        $this->personId = UuidV7::generate();
        $this->tenantId = UuidV7::generate();
        $this->membershipId = UuidV7::generate();
        $this->alternateTenantId = UuidV7::generate();
        $this->alternateMembershipId = UuidV7::generate();
        $this->email = sprintf(
            'canonical-browser-context-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createAuthenticationFixture();
    }

    public function test_canonical_me_accepts_browser_session_without_exposing_bearer(): void
    {
        $bearerCredential = $this->loginBrowserMembershipContextAndAttachCookie();

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipId,
            )
            ->getJson('/api/v1/auth/me');

        $response
            ->assertOk()
            ->assertExactJson(
                $this->expectedContextResponse(),
            );

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_browser_session_transport_ignores_browser_supplied_authorization_header(): void
    {
        $this->loginBrowserMembershipContextAndAttachCookie();

        $forgedBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userId,
                $this->alternateTenantId,
                $this->alternateMembershipId,
            );

        $this
            ->withHeaders([
                InjectBrowserTenantContext::HEADER => $this->membershipId,
                'Authorization' => 'Bearer '.$forgedBearer,
            ])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $this->membershipId,
            )
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantId,
            );
    }

    public function test_canonical_browser_me_requires_membership_locator(): void
    {
        $this->loginBrowserMembershipContextAndAttachCookie();

        $this
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                'message' => 'Browser membership context is required.',
            ]);
    }

    public function test_canonical_browser_me_does_not_create_unknown_membership_context(): void
    {
        $this->loginBrowserMembershipContextAndAttachCookie();

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->alternateMembershipId,
            )
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_MEMBERSHIP_CONTEXT_DENIED',
                'message' => 'Browser membership context is not available in this session.',
            ]);
    }

    public function test_canonical_browser_me_rejects_invalid_membership_locator(): void
    {
        $this->loginBrowserMembershipContextAndAttachCookie();

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                'not-a-uuid',
            )
            ->getJson('/api/v1/auth/me')
            ->assertUnprocessable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'INVALID_BROWSER_MEMBERSHIP_ID',
                'message' => 'Browser membership identifier is invalid.',
            ]);
    }

    public function test_canonical_browser_me_fails_closed_when_vault_locator_maps_to_different_canonical_membership(): void
    {
        $mismatchedBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userId,
                $this->alternateTenantId,
                $this->alternateMembershipId,
            );

        $this->withSession([
            'educore.browser_auth' => [
                'user_id' => $this->userId,
                'membership_credentials' => [
                    $this->membershipId => $mismatchedBearer,
                ],
            ],
        ]);

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app['session']->getId(),
            )
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipId,
            )
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_CONTEXT_MISMATCH',
                'message' => 'Browser session context does not match canonical authentication context.',
            ]);
    }

    public function test_browser_session_cookie_takes_precedence_over_bearer_when_session_is_not_authenticated(): void
    {
        $bearerCredential = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userId,
                $this->tenantId,
                $this->membershipId,
            );

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                Str::random(40),
            )
            ->withHeaders([
                InjectBrowserTenantContext::HEADER => $this->membershipId,
                'Authorization' => 'Bearer '.$bearerCredential,
            ])
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                'message' => 'Authenticated browser session is required.',
            ]);
    }

    public function test_canonical_me_keeps_bearer_transport_stateless_when_browser_cookie_is_absent(): void
    {
        $bearerCredential = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userId,
                $this->tenantId,
                $this->membershipId,
            );

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$bearerCredential,
            )
            ->getJson('/api/v1/auth/me');

        $response
            ->assertOk()
            ->assertExactJson(
                $this->expectedContextResponse(),
            )
            ->assertCookieMissing(
                $this->sessionCookieName(),
            );
    }

    public function test_canonical_me_route_uses_conditional_browser_transport_without_web_group(): void
    {
        $route = Route::getRoutes()->getByName(
            'api.v1.auth.me',
        );

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains(
            'api',
            $middleware,
        );
        $this->assertContains(
            UseBrowserSessionForCanonicalApi::class,
            $middleware,
        );
        $this->assertContains(
            InjectTransportAwareTenantContext::class,
            $middleware,
        );
        $this->assertNotContains(
            'web',
            $middleware,
        );

        $transportIndex = array_search(
            UseBrowserSessionForCanonicalApi::class,
            $middleware,
            true,
        );
        $contextIndex = array_search(
            InjectTransportAwareTenantContext::class,
            $middleware,
            true,
        );

        $this->assertIsInt($transportIndex);
        $this->assertIsInt($contextIndex);
        $this->assertLessThan(
            $contextIndex,
            $transportIndex,
            'BrowserSession activation must run before transport-aware context resolution.',
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
                    'identifier' => $this->email,
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
                $this->userId,
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
            $this->userId,
            $browserAuthState['user_id']
                ?? null,
        );

        $this->assertSame(
            [],
            $browserAuthState[
                'membership_credentials'
            ] ?? null,
            'Fresh Browser login must not establish Membership context.',
        );

        /*
         * /auth/me is Membership/Tenant-scoped, so prepare the canonical
         * Membership credential explicitly after global authentication.
         */
        $this
            ->postJson(
                sprintf(
                    '/api/v1/browser/user/memberships/%s/switch',
                    $this->membershipId,
                ),
            )
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'membership_id' =>
                        $this->membershipId,
                    'tenant_id' =>
                        $this->tenantId,
                    'tenant_name' =>
                        'Canonical Browser Context Tenant',
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
        ][$this->membershipId] ?? null;

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
            $this->userId,
            $claims['user_id']
                ?? null,
        );

        $this->assertSame(
            $this->tenantId,
            $claims['tenant_id']
                ?? null,
        );

        $this->assertSame(
            $this->membershipId,
            $claims['membership_id']
                ?? null,
        );

        /*
         * Laravel's feature client does not retain response cookies between
         * JSON requests automatically. Attach the current session identifier
         * to exercise the same canonical transport discriminator used by a
         * real browser.
         */
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

    /**
     * @return array<string, mixed>
     */
    private function expectedContextResponse(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => $this->userId,
                    'email' => $this->email,
                ],
                'person' => [
                    'id' => $this->personId,
                    'name' => 'Canonical Browser Context User',
                ],
                'membership' => [
                    'id' => $this->membershipId,
                    'status' => 'ACTIVE',
                ],
                'tenant' => [
                    'id' => $this->tenantId,
                    'name' => 'Canonical Browser Context Tenant',
                    'subdomain' => $this->tenantSubdomain(),
                ],
            ],
        ];
    }

    private function sessionCookieName(): string
    {
        $cookieName = config('session.cookie');

        $this->assertIsString($cookieName);
        $this->assertNotSame('', trim($cookieName));

        return $cookieName;
    }

    private function createAuthenticationFixture(): void
    {
        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Canonical Browser Context User',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'person_id' => $this->personId,
            'email' => $this->email,
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Canonical Browser Context Tenant',
            'subdomain' => $this->tenantSubdomain(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $this->alternateTenantId,
            'name' => 'Canonical Browser Alternate Tenant',
            'subdomain' => $this->alternateTenantSubdomain(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            [
                'id' => $this->membershipId,
                'person_id' => $this->personId,
                'tenant_id' => $this->tenantId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->alternateMembershipId,
                'person_id' => $this->personId,
                'tenant_id' => $this->alternateTenantId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function tenantSubdomain(): string
    {
        return 'canonical-browser-context';
    }

    private function alternateTenantSubdomain(): string
    {
        return 'canonical-browser-context-alt';
    }
}
