<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class BrowserLoginTest extends TestCase
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
        $this->email = sprintf(
            'browser-login-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createAuthenticationFixture();
    }

    public function test_browser_login_keeps_bearer_server_side_and_returns_only_safe_context(): void
    {
        $this->withSession([
            'pre_auth_marker' => 'preserved',
        ]);

        $beforeSessionId = $this->app['session']->getId();

        $response = $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'email' => $this->email,
                'password' => 'secret123',
                'tenant_uuid' => $this->tenantId,
            ],
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'membership_id' => $this->membershipId,
                    'tenant_id' => $this->tenantId,
                ],
            ])
            ->assertSessionHas(
                'pre_auth_marker',
                'preserved',
            );

        $afterSessionId = $this->app['session']->getId();

        $this->assertNotSame(
            $beforeSessionId,
            $afterSessionId,
            'Browser login must regenerate the pre-authentication session identifier.',
        );

        $browserAuthState = $this->app['session']->get(
            'educore.browser_auth',
        );

        $this->assertIsArray($browserAuthState);
        $this->assertSame(
            $this->userId,
            $browserAuthState['user_id'] ?? null,
        );

        $membershipCredentials = $browserAuthState[
            'membership_credentials'
        ] ?? null;

        $this->assertIsArray($membershipCredentials);

        $bearerCredential = $membershipCredentials[
            $this->membershipId
        ] ?? null;

        $this->assertIsString($bearerCredential);
        $this->assertNotSame('', trim($bearerCredential));
        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );

        $claims = $this->app
            ->make(TokenManagerInterface::class)
            ->validateAndExtract($bearerCredential);

        $this->assertIsArray($claims);
        $this->assertSame(
            $this->userId,
            $claims['user_id'] ?? null,
        );
        $this->assertSame(
            $this->tenantId,
            $claims['tenant_id'] ?? null,
        );
        $this->assertSame(
            $this->membershipId,
            $claims['membership_id'] ?? null,
        );
    }

    public function test_browser_login_rejects_invalid_credentials_without_authenticated_vault_state(): void
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'email' => $this->email,
                'password' => 'wrong-password',
                'tenant_uuid' => $this->tenantId,
            ],
        )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'AUTHENTICATION_FAILED',
                'message' => 'Invalid authentication credentials.',
            ]);

        $this->assertNull(
            $this->app['session']->get('educore.browser_auth'),
        );
    }

    public function test_browser_login_uses_same_canonical_input_normalization(): void
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'email' => sprintf(
                    '  %s  ',
                    strtoupper($this->email),
                ),
                'password' => 'secret123',
                'tenant_uuid' => sprintf(
                    '  %s  ',
                    $this->tenantId,
                ),
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.membership_id',
                $this->membershipId,
            );
    }

    public function test_browser_login_requires_canonical_login_fields(): void
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'password',
                'tenant_uuid',
            ]);
    }

    public function test_browser_login_fails_closed_when_server_side_vault_cannot_be_established(): void
    {
        $credentialVault = $this->createMock(
            BrowserSessionCredentialVaultInterface::class,
        );

        $credentialVault
            ->expects($this->once())
            ->method('establishForUser')
            ->with($this->userId)
            ->willThrowException(
                new RuntimeException(
                    'internal-browser-session-secret',
                ),
            );

        $credentialVault
            ->expects($this->once())
            ->method('clear');

        $credentialVault
            ->expects($this->never())
            ->method('storeMembershipCredential');

        $this->app->instance(
            BrowserSessionCredentialVaultInterface::class,
            $credentialVault,
        );

        $response = $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'email' => $this->email,
                'password' => 'secret123',
                'tenant_uuid' => $this->tenantId,
            ],
        );

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_UNAVAILABLE',
                'message' => 'Unable to establish a secure browser session.',
            ]);

        $this->assertStringNotContainsString(
            'internal-browser-session-secret',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
    }

    private function createAuthenticationFixture(): void
    {
        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Browser Login User',
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
            'name' => 'Browser Login Tenant',
            'subdomain' => sprintf(
                'browser-login-%s',
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
}
