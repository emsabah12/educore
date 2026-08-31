<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTransportAwareTenantContext;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class GlobalIdentityIntrospectionContractTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $personId;

    private string $tenantId;

    private string $membershipId;

    private string $email;

    private string $username;

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

        $suffix = Str::lower(
            Str::random(10),
        );

        $this->email = sprintf(
            'identity-introspection-%s@educore.test',
            $suffix,
        );

        $this->username = sprintf(
            'identity.%s',
            $suffix,
        );

        $this->createFixture();
    }

    public function test_identity_bearer_can_read_global_identity_without_membership_or_tenant_projection(): void
    {
        $bearerCredential = $this->app
            ->make(
                TokenManagerInterface::class,
            )
            ->issueIdentityToken(
                $this->userId,
            );

        $response = $this
            ->withToken(
                $bearerCredential,
            )
            ->getJson(
                '/api/v1/auth/identity',
            );

        $response
            ->assertOk()
            ->assertExactJson(
                $this->expectedIdentityResponse(),
            );

        $this->assertIdentityResponseHasNoTenantContext(
            $response->json(),
        );
    }

    public function test_membership_bearer_can_read_same_global_identity_without_membership_or_tenant_projection(): void
    {
        $bearerCredential = $this->app
            ->make(
                TokenManagerInterface::class,
            )
            ->issueMembershipToken(
                $this->userId,
                $this->tenantId,
                $this->membershipId,
            );

        $response = $this
            ->withToken(
                $bearerCredential,
            )
            ->getJson(
                '/api/v1/auth/identity',
            );

        $response
            ->assertOk()
            ->assertExactJson(
                $this->expectedIdentityResponse(),
            );

        $this->assertIdentityResponseHasNoTenantContext(
            $response->json(),
        );
    }

    public function test_identity_only_browser_session_can_read_global_identity_without_membership_credential(): void
    {
        $this->loginBrowserIdentityAndAttachCookie();

        $browserAuthState = $this->browserAuthState();

        $this->assertSame(
            [],
            $browserAuthState[
                'membership_credentials'
            ] ?? null,
            'Global identity introspection must work before Membership selection.',
        );

        $response = $this->getJson(
            '/api/v1/auth/identity',
        );

        $response
            ->assertOk()
            ->assertExactJson(
                $this->expectedIdentityResponse(),
            );

        $this->assertIdentityResponseHasNoTenantContext(
            $response->json(),
        );
    }

    public function test_identity_introspection_ignores_browser_supplied_authorization_header(): void
    {
        $this->loginBrowserIdentityAndAttachCookie();

        $otherPersonId = UuidV7::generate();
        $otherUserId = UuidV7::generate();
        $otherTenantId = UuidV7::generate();
        $otherMembershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $otherPersonId,
            'name' => 'Identity Introspection Other Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $otherUserId,
            'person_id' => $otherPersonId,
            'email' => sprintf(
                'identity-introspection-other-%s@educore.test',
                Str::lower(
                    Str::random(10),
                ),
            ),
            'username' => sprintf(
                'identity.other.%s',
                Str::lower(
                    Str::random(8),
                ),
            ),
            'password' => bcrypt(
                'secret123',
            ),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $otherTenantId,
            'name' => 'Identity Introspection Other Tenant',
            'subdomain' => sprintf(
                'identity-other-%s',
                Str::lower(
                    Str::random(8),
                ),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $otherMembershipId,
            'person_id' => $otherPersonId,
            'tenant_id' => $otherTenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $forgedBearer = $this->app
            ->make(
                TokenManagerInterface::class,
            )
            ->issueMembershipToken(
                $otherUserId,
                $otherTenantId,
                $otherMembershipId,
            );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$forgedBearer,
            )
            ->getJson(
                '/api/v1/auth/identity',
            )
            ->assertOk()
            ->assertExactJson(
                $this->expectedIdentityResponse(),
            );
    }

    public function test_identity_introspection_requires_authentication_for_stateless_transport(): void
    {
        $this
            ->getJson(
                '/api/v1/auth/identity',
            )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' =>
                    'Unauthenticated. Invalid or missing identity context.',
            ]);
    }

    public function test_identity_introspection_requires_authenticated_browser_session_when_browser_transport_is_selected(): void
    {
        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                Str::random(40),
            )
            ->getJson(
                '/api/v1/auth/identity',
            )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' =>
                    'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                'message' =>
                    'Authenticated browser session is required.',
            ]);
    }

    public function test_identity_introspection_route_uses_identity_only_dual_transport(): void
    {
        $route = Route::getRoutes()->getByName(
            'api.v1.auth.identity',
        );

        $this->assertNotNull(
            $route,
        );

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
            InjectTransportAwareAuthenticatedUser::class,
            $middleware,
        );

        $this->assertNotContains(
            'web',
            $middleware,
        );

        $this->assertNotContains(
            InjectTenantContext::class,
            $middleware,
        );

        $this->assertNotContains(
            InjectTransportAwareTenantContext::class,
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

        $this->assertIsInt(
            $transportIndex,
        );

        $this->assertIsInt(
            $identityIndex,
        );

        $this->assertLessThan(
            $identityIndex,
            $transportIndex,
            'Browser transport activation must run before global identity resolution.',
        );
    }

    private function loginBrowserIdentityAndAttachCookie(): void
    {
        $this
            ->postJson(
                '/api/v1/browser/auth/login',
                [
                    'identifier' =>
                        $this->email,
                    'password' =>
                        'secret123',
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

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app[
                    'session'
                ]->getId(),
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function browserAuthState(): array
    {
        $state = $this->app[
            'session'
        ]->get(
            'educore.browser_auth',
        );

        $this->assertIsArray(
            $state,
        );

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedIdentityResponse(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'context_type' => 'identity',
                'user' => [
                    'id' =>
                        $this->userId,
                    'name' =>
                        'Identity Introspection Person',
                    'email' =>
                        $this->email,
                    'username' =>
                        $this->username,
                ],
                'platform' => [
                    'is_superadmin' => true,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertIdentityResponseHasNoTenantContext(
        array $response,
    ): void {
        $data = $response['data'] ?? null;

        $this->assertIsArray(
            $data,
        );

        $this->assertArrayNotHasKey(
            'membership',
            $data,
        );

        $this->assertArrayNotHasKey(
            'membership_id',
            $data,
        );

        $this->assertArrayNotHasKey(
            'tenant',
            $data,
        );

        $this->assertArrayNotHasKey(
            'tenant_id',
            $data,
        );

        $this->assertArrayNotHasKey(
            'access_token',
            $data,
        );
    }

    private function sessionCookieName(): string
    {
        $cookieName = config(
            'session.cookie',
        );

        $this->assertIsString(
            $cookieName,
        );

        $this->assertNotSame(
            '',
            trim(
                $cookieName,
            ),
        );

        return $cookieName;
    }

    private function createFixture(): void
    {
        DB::table('persons')->insert([
            'id' =>
                $this->personId,
            'name' =>
                'Identity Introspection Person',
            'status' =>
                'ACTIVE',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        DB::table('users')->insert([
            'id' =>
                $this->userId,
            'person_id' =>
                $this->personId,
            'email' =>
                $this->email,
            'username' =>
                $this->username,
            'password' =>
                bcrypt(
                    'secret123',
                ),
            'status' =>
                'ACTIVE',
            'is_superadmin' =>
                true,
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        DB::table('tenants')->insert([
            'id' =>
                $this->tenantId,
            'name' =>
                'Identity Introspection Tenant',
            'subdomain' =>
                sprintf(
                    'identity-introspection-%s',
                    Str::lower(
                        Str::random(8),
                    ),
                ),
            'is_active' =>
                true,
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);

        DB::table('memberships')->insert([
            'id' =>
                $this->membershipId,
            'person_id' =>
                $this->personId,
            'tenant_id' =>
                $this->tenantId,
            'status' =>
                'ACTIVE',
            'created_at' =>
                now(),
            'updated_at' =>
                now(),
        ]);
    }
}
