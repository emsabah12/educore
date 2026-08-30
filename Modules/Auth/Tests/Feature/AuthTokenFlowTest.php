<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class AuthTokenFlowTest extends TestCase
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

        /*
         * Audit persistence is outside this integration-flow contract.
         *
         * Authentication, credential exchange, middleware, revocation, and
         * Tenant/Membership boundaries remain real.
         */
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
            'auth-token-flow-%s@educore.test',
            $suffix,
        );

        $this->username = sprintf(
            'auth.%s',
            $suffix,
        );

        $this->createAuthenticationFixture();
    }

    public function test_user_can_login_discover_switch_access_tenant_context_and_logout(): void
    {
        $identityToken = $this->loginIdentityToken();

        /*
         * Identity Credential is sufficient for global Membership discovery.
         */
        $membershipResponse = $this
            ->withToken($identityToken)
            ->getJson('/api/v1/user/my-memberships');

        $membershipResponse
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.0.membership_id',
                $this->membershipId,
            )
            ->assertJsonPath(
                'data.0.membership_status',
                'ACTIVE',
            )
            ->assertJsonPath(
                'data.0.tenant_id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.0.tenant_name',
                'Authentication Flow Tenant',
            );

        /*
         * Identity Credential must not silently become Membership/Tenant
         * authority.
         */
        $this
            ->withToken($identityToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_CONTEXT_DENIED',
            );

        /*
         * Explicit switch exchanges Identity Context for a selected
         * Membership/Tenant credential.
         */
        $switchResponse = $this
            ->withToken($identityToken)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipId,
                ),
            );

        $switchResponse
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.token_type',
                'Bearer',
            )
            ->assertJsonPath(
                'data.context.membership_id',
                $this->membershipId,
            )
            ->assertJsonPath(
                'data.context.tenant_id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.context.tenant_name',
                'Authentication Flow Tenant',
            );

        $membershipToken = $switchResponse->json(
            'data.access_token',
        );

        $this->assertIsString(
            $membershipToken,
        );

        $this->assertNotSame(
            '',
            trim($membershipToken),
        );

        /*
         * Membership Credential can enter canonical Tenant context.
         */
        $meResponse = $this
            ->withToken($membershipToken)
            ->getJson('/api/v1/auth/me');

        $meResponse
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.user.id',
                $this->userId,
            )
            ->assertJsonPath(
                'data.user.email',
                $this->email,
            )
            ->assertJsonPath(
                'data.person.id',
                $this->personId,
            )
            ->assertJsonPath(
                'data.person.name',
                'Authentication Flow User',
            )
            ->assertJsonPath(
                'data.membership.id',
                $this->membershipId,
            )
            ->assertJsonPath(
                'data.membership.status',
                'ACTIVE',
            )
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.tenant.name',
                'Authentication Flow Tenant',
            );

        $logoutResponse = $this
            ->withToken($membershipToken)
            ->postJson('/api/v1/auth/logout');

        $logoutResponse
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.token_revoked',
                true,
            );

        /*
         * Explicitly revoked Membership credential cannot be reused.
         */
        $this
            ->withToken($membershipToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            );
    }

    public function test_global_login_issues_explicit_identity_credential(): void
    {
        $identityToken = $this->loginIdentityToken();

        $claims = app(
            TokenManagerInterface::class,
        )->validateAndExtract(
            $identityToken,
        );

        $this->assertIsArray(
            $claims,
        );

        $this->assertSame(
            'identity',
            $claims['credential_type']
                ?? null,
        );

        $this->assertSame(
            $this->userId,
            $claims['user_id']
                ?? null,
        );

        $this->assertArrayNotHasKey(
            'tenant_id',
            $claims,
        );

        $this->assertArrayNotHasKey(
            'membership_id',
            $claims,
        );
    }

    public function test_membership_switch_issues_explicit_typed_membership_credential(): void
    {
        $membershipToken = $this->switchToFixtureMembership(
            $this->loginIdentityToken(),
        );

        $claims = app(
            TokenManagerInterface::class,
        )->validateAndExtract(
            $membershipToken,
        );

        $this->assertIsArray(
            $claims,
        );

        $this->assertSame(
            'membership',
            $claims['credential_type']
                ?? null,
            'Membership switch must issue the explicit typed Membership Credential contract.',
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

        $this->assertIsInt(
            $claims['expires_at']
                ?? null,
        );
    }

    public function test_identity_credential_cannot_access_membership_context(): void
    {
        $identityToken = $this->loginIdentityToken();

        $this
            ->withToken($identityToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_CONTEXT_DENIED',
            );
    }

    public function test_login_is_rejected_for_invalid_password(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'identifier' => $this->email,
                    'password' => 'wrong-password',
                ],
            )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'AUTHENTICATION_FAILED',
                'message' =>
                    'Invalid authentication credentials.',
            ]);
    }

    public function test_membership_switch_rejects_unowned_or_unknown_membership(): void
    {
        $identityToken = $this->loginIdentityToken();

        $this
            ->withToken($identityToken)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    UuidV7::generate(),
                ),
            )
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'MEMBERSHIP_SWITCH_DENIED',
                'message' =>
                    'Requested membership is not available for this user.',
            ]);
    }

    public function test_me_requires_bearer_token(): void
    {
        $this
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            );
    }

    public function test_logout_requires_membership_bearer_token(): void
    {
        $this
            ->postJson('/api/v1/auth/logout')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            );

        /*
         * An Identity Credential also cannot enter the Membership-scoped
         * stateless logout route.
         */
        $this
            ->withToken(
                $this->loginIdentityToken(),
            )
            ->postJson('/api/v1/auth/logout')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_CONTEXT_DENIED',
            );
    }

    public function test_login_requires_identifier_and_password_only(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/login-token',
            [],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'identifier',
                'password',
            ])
            ->assertJsonMissingValidationErrors([
                'email',
                'tenant_uuid',
            ]);
    }

    public function test_login_rejects_short_password_before_authentication(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'identifier' => $this->email,
                    'password' => 'short',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'password',
            ])
            ->assertJsonMissingValidationErrors([
                'identifier',
            ]);
    }

    public function test_login_normalizes_email_identifier_before_lookup(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'identifier' => sprintf(
                    '  %s  ',
                    strtoupper(
                        $this->email,
                    ),
                ),
                'password' => 'secret123',
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.user.email',
                $this->email,
            )
            ->assertJsonPath(
                'data.context_type',
                'identity',
            );
    }

    public function test_username_identifier_can_authenticate_globally(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'identifier' => strtoupper(
                        $this->username,
                    ),
                    'password' => 'secret123',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.user.id',
                $this->userId,
            )
            ->assertJsonPath(
                'data.user.username',
                $this->username,
            )
            ->assertJsonPath(
                'data.context_type',
                'identity',
            );
    }

    public function test_login_reports_actual_token_manager_lifetime(): void
    {
        $tokenManager = app(
            TokenManagerInterface::class,
        );

        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'identifier' => $this->email,
                    'password' => 'secret123',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.expires_in',
                $tokenManager->lifetimeInSeconds(),
            );
    }

    public function test_revoked_membership_token_cannot_access_tenant_context(): void
    {
        $membershipToken = $this->switchToFixtureMembership(
            $this->loginIdentityToken(),
        );

        $claims = app(
            TokenManagerInterface::class,
        )->validateAndExtract(
            $membershipToken,
        );

        $this->assertIsArray(
            $claims,
        );

        $expiresAt = $claims['expires_at']
            ?? null;

        $this->assertIsInt(
            $expiresAt,
        );

        app(
            TokenRevocationStoreInterface::class,
        )->revoke(
            token: $membershipToken,
            expiresAt: $expiresAt,
        );

        $this
            ->withToken($membershipToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            );
    }

    private function loginIdentityToken(): string
    {
        $response = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'identifier' => $this->email,
                'password' => 'secret123',
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.token_type',
                'Bearer',
            )
            ->assertJsonPath(
                'data.context_type',
                'identity',
            )
            ->assertJsonPath(
                'data.user.id',
                $this->userId,
            )
            ->assertJsonPath(
                'data.user.email',
                $this->email,
            );

        $accessToken = $response->json(
            'data.access_token',
        );

        $this->assertIsString(
            $accessToken,
        );

        $this->assertNotSame(
            '',
            trim($accessToken),
        );

        return $accessToken;
    }

    private function switchToFixtureMembership(
        string $identityToken,
    ): string {
        $response = $this
            ->withToken($identityToken)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipId,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.context.membership_id',
                $this->membershipId,
            )
            ->assertJsonPath(
                'data.context.tenant_id',
                $this->tenantId,
            );

        $accessToken = $response->json(
            'data.access_token',
        );

        $this->assertIsString(
            $accessToken,
        );

        $this->assertNotSame(
            '',
            trim($accessToken),
        );

        return $accessToken;
    }

    private function createAuthenticationFixture(): void
    {
        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Authentication Flow User',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'person_id' => $this->personId,
            'email' => $this->email,
            'username' => $this->username,
            'password' => bcrypt(
                'secret123',
            ),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Authentication Flow Tenant',
            'subdomain' => sprintf(
                'auth-flow-%s',
                Str::lower(
                    Str::random(10),
                ),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->membershipId,
            'person_id' => $this->personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
