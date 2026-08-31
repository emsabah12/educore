<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionAuthenticationCredentialProviderInterface;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareAuthenticatedUser;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class CanonicalBrowserMembershipDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $userAId;

    private string $personAId;

    private string $userBId;

    private string $personBId;

    private string $tenantAId;

    private string $tenantBId;

    private string $tenantBForOtherUserId;

    private string $membershipAId;

    private string $membershipBId;

    private string $otherUserMembershipId;

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
        $this->tenantBForOtherUserId = UuidV7::generate();
        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();
        $this->otherUserMembershipId = UuidV7::generate();
        $this->emailA = sprintf(
            'canonical-browser-memberships-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createFixture();
    }

    public function test_canonical_membership_discovery_accepts_browser_session_without_membership_locator_or_bearer_exposure(): void
    {
        $this->loginBrowserIdentityAndAttachCookie();

        $response = $this->getJson(
            '/api/v1/user/my-memberships',
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    [
                        'membership_id' => $this->membershipAId,
                        'membership_status' => 'ACTIVE',
                        'tenant_id' => $this->tenantAId,
                        'tenant_name' => 'Canonical Membership Tenant A',
                        'tenant_subdomain' => 'canonical-membership-a',
                    ],
                    [
                        'membership_id' => $this->membershipBId,
                        'membership_status' => 'ACTIVE',
                        'tenant_id' => $this->tenantBId,
                        'tenant_name' => 'Canonical Membership Tenant B',
                        'tenant_subdomain' => 'canonical-membership-b',
                    ],
                ],
            ]);

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
    }

    public function test_browser_membership_discovery_ignores_browser_supplied_authorization_header(): void
    {
        $this->loginBrowserIdentityAndAttachCookie();

        $forgedBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userBId,
                $this->tenantBForOtherUserId,
                [
                    'membership_id' => $this->otherUserMembershipId,
                ],
            );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$forgedBearer,
            )
            ->getJson('/api/v1/user/my-memberships')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'membership_id' => $this->membershipAId,
                'tenant_id' => $this->tenantAId,
            ])
            ->assertJsonFragment([
                'membership_id' => $this->membershipBId,
                'tenant_id' => $this->tenantBId,
            ])
            ->assertJsonMissing([
                'membership_id' => $this->otherUserMembershipId,
            ]);
    }

    public function test_browser_session_cookie_takes_precedence_over_bearer_for_membership_discovery(): void
    {
        $bearerCredential = $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
                [
                    'membership_id' => $this->membershipAId,
                ],
            );

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                Str::random(40),
            )
            ->withHeader(
                'Authorization',
                'Bearer '.$bearerCredential,
            )
            ->getJson('/api/v1/user/my-memberships')
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                'message' => 'Authenticated browser session is required.',
            ]);
    }

    public function test_browser_membership_discovery_uses_browser_identity_without_authentication_credential_candidate(): void
    {
        $this->loginBrowserIdentityAndAttachCookie();

        $credentialProvider = $this->createMock(
            BrowserSessionAuthenticationCredentialProviderInterface::class,
        );

        $credentialProvider
            ->expects($this->never())
            ->method(
                'credentialForAuthentication',
            );

        $this->app->instance(
            BrowserSessionAuthenticationCredentialProviderInterface::class,
            $credentialProvider,
        );

        $this
            ->getJson('/api/v1/user/my-memberships')
            ->assertOk()
            ->assertJsonCount(
                2,
                'data',
            )
            ->assertJsonFragment([
                'membership_id' => $this->membershipAId,
                'tenant_id' => $this->tenantAId,
            ])
            ->assertJsonFragment([
                'membership_id' => $this->membershipBId,
                'tenant_id' => $this->tenantBId,
            ])
            ->assertJsonMissing([
                'membership_id' =>
                    $this->otherUserMembershipId,
            ]);
    }

    public function test_canonical_membership_discovery_keeps_bearer_transport_stateless(): void
    {
        $bearerCredential = $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userAId,
                $this->tenantAId,
            );

        $this
            ->withToken($bearerCredential)
            ->getJson('/api/v1/user/my-memberships')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertCookieMissing(
                $this->sessionCookieName(),
            );
    }

    public function test_canonical_membership_discovery_route_uses_user_only_dual_transport_without_web_or_tenant_context(): void
    {
        $route = Route::getRoutes()->getByName(
            'api.v1.user.memberships.index',
        );

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('api', $middleware);
        $this->assertContains(
            UseBrowserSessionForCanonicalApi::class,
            $middleware,
        );
        $this->assertContains(
            InjectTransportAwareAuthenticatedUser::class,
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
        $identityIndex = array_search(
            InjectTransportAwareAuthenticatedUser::class,
            $middleware,
            true,
        );

        $this->assertIsInt($transportIndex);
        $this->assertIsInt($identityIndex);
        $this->assertLessThan(
            $identityIndex,
            $transportIndex,
            'BrowserSession activation must run before transport-aware user identity resolution.',
        );
    }

    private function loginBrowserIdentityAndAttachCookie(): void
    {
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
            'Membership discovery must begin from Browser Identity Context without Membership credentials.',
        );

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app[
                    'session'
                ]->getId(),
            );
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
                'name' => 'Canonical Membership User A',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->personBId,
                'name' => 'Canonical Membership User B',
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
                    'canonical-browser-memberships-other-%s@educore.test',
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
                'Canonical Membership Tenant A',
                'canonical-membership-a',
            ),
            $this->tenantData(
                $this->tenantBId,
                'Canonical Membership Tenant B',
                'canonical-membership-b',
            ),
            $this->tenantData(
                $this->tenantBForOtherUserId,
                'Canonical Membership Other Tenant',
                'canonical-membership-other',
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
                $this->otherUserMembershipId,
                $this->personBId,
                $this->tenantBForOtherUserId,
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
