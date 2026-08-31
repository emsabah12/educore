<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class BrowserLoginTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $personId;

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

        $suffix = Str::lower(
            Str::random(10),
        );

        $this->email = sprintf(
            'browser-login-%s@educore.test',
            $suffix,
        );

        $this->username = sprintf(
            'browser.%s',
            $suffix,
        );

        $this->createGlobalIdentityFixture();
    }

    public function test_browser_login_establishes_fresh_global_identity_without_membership_context(): void
    {
        $this->withSession([
            'pre_auth_marker' => 'preserved',
        ]);

        $beforeSessionId = $this->app[
            'session'
        ]->getId();

        $response = $this->postJson(
            '/api/v1/browser/auth/login',
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
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'context_type' => 'identity',
                    'user' => [
                        'id' => $this->userId,
                        'name' => 'Browser Login User',
                        'email' => $this->email,
                        'username' => $this->username,
                    ],
                    'platform' => [
                        'is_superadmin' => false,
                    ],
                ],
            ])
            ->assertSessionHas(
                'pre_auth_marker',
                'preserved',
            );

        $afterSessionId = $this->app[
            'session'
        ]->getId();

        $this->assertNotSame(
            $beforeSessionId,
            $afterSessionId,
            'Browser login must regenerate the pre-authentication session identifier.',
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
            $browserAuthState['membership_credentials']
                ?? null,
            'Fresh Browser login must not establish Membership/Tenant context.',
        );

        $responseContent = $response->getContent();

        $this->assertStringNotContainsString(
            'access_token',
            $responseContent,
        );

        $this->assertStringNotContainsString(
            'tenant_id',
            $responseContent,
        );

        $this->assertStringNotContainsString(
            'membership_id',
            $responseContent,
        );

        /*
         * Successful global Browser authentication must not depend on
         * Membership/Tenant availability.
         */
        $this->assertDatabaseMissing(
            'memberships',
            [
                'person_id' => $this->personId,
            ],
        );
    }

    public function test_browser_username_identifier_authenticates_globally(): void
    {
        $this
            ->postJson(
                '/api/v1/browser/auth/login',
                [
                    'identifier' => strtoupper(
                        $this->username,
                    ),
                    'password' => 'secret123',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
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
                'data.user.username',
                $this->username,
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
            $browserAuthState['membership_credentials']
                ?? null,
        );
    }

    public function test_browser_login_rejects_invalid_credentials_without_authenticated_vault_state(): void
    {
        $this
            ->postJson(
                '/api/v1/browser/auth/login',
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

        $this->assertNull(
            $this->app[
                'session'
            ]->get(
                'educore.browser_auth',
            ),
        );
    }

    public function test_browser_login_requires_identifier_and_password_only(): void
    {
        $this
            ->postJson(
                '/api/v1/browser/auth/login',
                [],
            )
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

    public function test_browser_login_fails_closed_when_fresh_identity_cannot_be_established(): void
    {
        $credentialVault = $this->createMock(
            BrowserSessionCredentialVaultInterface::class,
        );

        $credentialVault
            ->expects($this->once())
            ->method(
                'establishFreshIdentity',
            )
            ->with(
                $this->userId,
            )
            ->willThrowException(
                new RuntimeException(
                    'internal-browser-session-secret',
                ),
            );

        $credentialVault
            ->expects($this->once())
            ->method(
                'clear',
            );

        $credentialVault
            ->expects($this->never())
            ->method(
                'establishForUser',
            );

        $credentialVault
            ->expects($this->never())
            ->method(
                'storeMembershipCredential',
            );

        $this->app->instance(
            BrowserSessionCredentialVaultInterface::class,
            $credentialVault,
        );

        $response = $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'identifier' => $this->email,
                'password' => 'secret123',
            ],
        );

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_UNAVAILABLE',
                'message' =>
                    'Unable to establish a secure browser session.',
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

    public function test_fresh_browser_login_discards_previous_same_user_membership_inventory(): void
    {
        $credentialVault = $this->app->make(
            BrowserSessionCredentialVaultInterface::class,
        );

        $previousMembershipId = UuidV7::generate();

        /*
         * Simulate stale BrowserSession state for the same User.
         */
        $credentialVault->establishForUser(
            $this->userId,
        );

        $credentialVault
            ->storeMembershipCredential(
                $previousMembershipId,
                'previous-membership-bearer',
            );

        $this->assertSame(
            'previous-membership-bearer',
            $credentialVault
                ->credentialForMembership(
                    $previousMembershipId,
                ),
        );

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
            );

        $this->assertSame(
            $this->userId,
            $credentialVault->userId(),
        );

        $this->assertNull(
            $credentialVault
                ->credentialForMembership(
                    $previousMembershipId,
                ),
            'Fresh Browser login must not inherit same-user Membership credentials.',
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
            [],
            $browserAuthState['membership_credentials']
                ?? null,
        );
    }

    private function createGlobalIdentityFixture(): void
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
            'username' => $this->username,
            'password' => bcrypt(
                'secret123',
            ),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Deliberately no Tenant and no Membership.
         *
         * Browser authentication must establish global Identity Context
         * before any Membership selection occurs.
         */
    }
}
