<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Tests\TestCase;

final class AuthTokenFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;
    private string $personId;
    private string $tenantId;
    private string $membershipId;

    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Audit bukan fokus test authentication flow ini.
         * Kita mengganti implementasinya dengan stub agar test tetap
         * menguji controller, repository, token manager, dan middleware.
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

        $this->email = sprintf(
            'auth-token-flow-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createAuthenticationFixture();
    }

    public function test_user_can_login_access_tenant_context_and_logout(): void
    {
        $loginResponse = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'email' => $this->email,
                'password' => 'secret123',
                'tenant_uuid' => $this->tenantId,
            ],
        );

        $loginResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath(
                'data.context.user_id',
                $this->userId,
            )
            ->assertJsonPath(
                'data.context.name',
                'Authentication Flow User',
            )
            ->assertJsonPath(
                'data.context.tenant_id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.context.membership_id',
                $this->membershipId,
            );

        $accessToken = $loginResponse->json(
            'data.access_token',
        );

        $this->assertIsString($accessToken);
        $this->assertNotSame('', trim($accessToken));

        $meResponse = $this
            ->withToken($accessToken)
            ->getJson('/api/v1/auth/me');

        $meResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'current_tenant',
                $this->tenantId,
            );

        $logoutResponse = $this
            ->withToken($accessToken)
            ->postJson('/api/v1/auth/logout');

        $logoutResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'data.token_revoked',
                true,
            );

        /*
        * Bearer credential yang sama tidak boleh digunakan kembali
        * setelah explicit logout.
        */
        $this
            ->withToken($accessToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            );
    }

    public function test_login_is_rejected_for_invalid_password(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'email' => $this->email,
                    'password' => 'wrong-password',
                    'tenant_uuid' => $this->tenantId,
                ],
            )
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_login_is_rejected_for_wrong_tenant(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'email' => $this->email,
                    'password' => 'secret123',
                    'tenant_uuid' => Str::uuid()->toString(),
                ],
            )
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_me_requires_bearer_token(): void
    {
        $this
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('status', 'error');
    }

    public function test_logout_requires_bearer_token(): void
    {
        $this
            ->postJson('/api/v1/auth/logout')
            ->assertForbidden()
            ->assertJsonPath('status', 'error');
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
            'password' => bcrypt('secret123'),
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
                Str::lower(Str::random(10)),
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

    public function test_login_requires_all_credentials(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'password',
                'tenant_uuid',
            ]);
    }

    public function test_login_rejects_invalid_email(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'email' => 'not-an-email',
                    'password' => 'secret123',
                    'tenant_uuid' => $this->tenantId,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
            ])
            ->assertJsonMissingValidationErrors([
                'password',
                'tenant_uuid',
            ]);
    }

    public function test_login_rejects_invalid_tenant_uuid(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'email' => $this->email,
                    'password' => 'secret123',
                    'tenant_uuid' => 'invalid-tenant-id',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tenant_uuid',
            ])
            ->assertJsonMissingValidationErrors([
                'email',
                'password',
            ]);
    }

    public function test_login_rejects_short_password_before_authentication(): void
    {
        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'email' => $this->email,
                    'password' => 'short',
                    'tenant_uuid' => $this->tenantId,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'password',
            ]);
    }

    public function test_login_normalizes_email_before_lookup(): void
    {
        $loginResponse = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'email' => sprintf(
                    '  %s  ',
                    strtoupper($this->email),
                ),
                'password' => 'secret123',
                'tenant_uuid' => $this->tenantId,
            ],
        );

        $loginResponse
            ->assertOk()
            ->assertJsonPath(
                'data.context.email',
                $this->email,
            );
    }

    public function test_login_reports_actual_token_manager_lifetime(): void
    {
        $tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        $this
            ->postJson(
                '/api/v1/auth/login-token',
                [
                    'email' => $this->email,
                    'password' => 'secret123',
                    'tenant_uuid' => $this->tenantId,
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.expires_in',
                $tokenManager->lifetimeInSeconds(),
            );
    }

    public function test_revoked_token_cannot_access_tenant_context(): void
    {
        $loginResponse = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'email' => $this->email,
                'password' => 'secret123',
                'tenant_uuid' => $this->tenantId,
            ],
        );

        $loginResponse->assertOk();

        $accessToken = $loginResponse->json(
            'data.access_token',
        );

        $this->assertIsString(
            $accessToken,
        );

        $tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        /*
     * Token masih valid sebelum direvoke.
     */
        $claims = $tokenManager->validateAndExtract(
            $accessToken,
        );

        $this->assertIsArray(
            $claims,
        );

        $expiresAt = $claims['expires_at'] ?? null;

        $this->assertIsInt(
            $expiresAt,
        );

        $revocationStore = $this->app->make(
            TokenRevocationStoreInterface::class,
        );

        $revocationStore->revoke(
            token: $accessToken,
            expiresAt: $expiresAt,
        );

        /*
     * Exact bearer credential yang sama sekarang tidak lagi
     * boleh membentuk authenticated tenant context.
     */
        $this
            ->withToken($accessToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'status',
                'error',
            );
    }
}
