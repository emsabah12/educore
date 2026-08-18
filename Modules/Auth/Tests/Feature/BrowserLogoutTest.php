<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class BrowserLogoutTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $membershipAId;

    private string $membershipBId;

    private string $tenantAId;

    private string $tenantBId;

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
        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();
        $this->tenantAId = UuidV7::generate();
        $this->tenantBId = UuidV7::generate();
    }

    public function test_browser_logout_revokes_all_held_credentials_and_invalidates_shared_session(): void
    {
        [$credentialA, $credentialB] = $this->createTwoCredentials();

        $this->withSession($this->browserSessionState(
            $credentialA,
            $credentialB,
        ));

        $session = $this->app['session'];
        $beforeSessionId = $session->getId();
        $beforeCsrfToken = $session->token();

        $response = $this->postJson(
            '/api/v1/browser/auth/logout',
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'message' => 'Logout completed successfully.',
            ])
            ->assertSessionMissing('educore.browser_auth')
            ->assertSessionMissing('protected_marker');

        $this->assertNotSame(
            $beforeSessionId,
            $session->getId(),
            'Browser logout must invalidate the authenticated session identifier.',
        );
        $this->assertNotSame(
            $beforeCsrfToken,
            $session->token(),
            'Browser logout must rotate the CSRF token for the new anonymous session.',
        );

        $revocationStore = $this->app->make(
            TokenRevocationStoreInterface::class,
        );

        $this->assertTrue(
            $revocationStore->isRevoked($credentialA),
        );
        $this->assertTrue(
            $revocationStore->isRevoked($credentialB),
        );

        $tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        $this->assertNull(
            $tokenManager->validateAndExtract($credentialA),
        );
        $this->assertNull(
            $tokenManager->validateAndExtract($credentialB),
        );

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $credentialA,
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $credentialB,
            $response->getContent(),
        );
    }

    public function test_browser_logout_is_idempotent_for_anonymous_browser_session(): void
    {
        $this->withSession([
            'anonymous_marker' => 'must-be-cleared',
        ]);

        $beforeSessionId = $this->app['session']->getId();

        $this->postJson(
            '/api/v1/browser/auth/logout',
        )
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'message' => 'Logout completed successfully.',
            ])
            ->assertSessionMissing('anonymous_marker');

        $this->assertNotSame(
            $beforeSessionId,
            $this->app['session']->getId(),
        );
    }

    public function test_browser_logout_attempts_all_revocations_and_still_invalidates_session_when_one_revocation_fails(): void
    {
        [$credentialA, $credentialB] = $this->createTwoCredentials();

        $this->withSession($this->browserSessionState(
            $credentialA,
            $credentialB,
        ));

        $attemptedTokens = [];
        $revocationStore = $this->createMock(
            TokenRevocationStoreInterface::class,
        );

        $revocationStore
            ->expects($this->exactly(2))
            ->method('revoke')
            ->willReturnCallback(
                static function (
                    string $token,
                    int $expiresAt,
                ) use (&$attemptedTokens, $credentialA): void {
                    $attemptedTokens[] = [
                        'token' => $token,
                        'expires_at' => $expiresAt,
                    ];

                    if ($token === $credentialA) {
                        throw new RuntimeException(
                            'internal-browser-revocation-secret',
                        );
                    }
                },
            );

        $this->app->instance(
            TokenRevocationStoreInterface::class,
            $revocationStore,
        );

        $response = $this->postJson(
            '/api/v1/browser/auth/logout',
        );

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'LOGOUT_UNAVAILABLE',
                'message' => 'Unable to complete logout securely.',
            ])
            ->assertSessionMissing('educore.browser_auth')
            ->assertSessionMissing('protected_marker');

        $this->assertCount(2, $attemptedTokens);
        $this->assertSame(
            [$credentialA, $credentialB],
            array_column($attemptedTokens, 'token'),
        );
        $this->assertStringNotContainsString(
            'internal-browser-revocation-secret',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
    }

    public function test_browser_logout_fails_closed_for_corrupt_credential_but_still_destroys_session(): void
    {
        $this->withSession([
            'educore.browser_auth' => [
                'user_id' => $this->userId,
                'membership_credentials' => [
                    $this->membershipAId => 'corrupt-browser-credential',
                ],
            ],
            'protected_marker' => 'must-be-cleared',
        ]);

        $this->postJson(
            '/api/v1/browser/auth/logout',
        )
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'LOGOUT_UNAVAILABLE',
                'message' => 'Unable to complete logout securely.',
            ])
            ->assertSessionMissing('educore.browser_auth')
            ->assertSessionMissing('protected_marker');
    }

    public function test_browser_logout_route_uses_web_boundary_and_session_blocking(): void
    {
        $route = Route::getRoutes()->getByName(
            'api.v1.browser.auth.logout',
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'web',
            $route->gatherMiddleware(),
        );
        $this->assertSame(10, $route->locksFor());
        $this->assertSame(10, $route->waitsFor());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createTwoCredentials(): array
    {
        $tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        return [
            $tokenManager->issueToken(
                $this->userId,
                $this->tenantAId,
                [
                    'membership_id' => $this->membershipAId,
                ],
            ),
            $tokenManager->issueToken(
                $this->userId,
                $this->tenantBId,
                [
                    'membership_id' => $this->membershipBId,
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function browserSessionState(
        string $credentialA,
        string $credentialB,
    ): array {
        return [
            'educore.browser_auth' => [
                'user_id' => $this->userId,
                'membership_credentials' => [
                    $this->membershipAId => $credentialA,
                    $this->membershipBId => $credentialB,
                ],
            ],
            'protected_marker' => 'must-be-cleared',
        ];
    }
}
