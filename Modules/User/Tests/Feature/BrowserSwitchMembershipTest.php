<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class BrowserSwitchMembershipTest extends TestCase
{
    use RefreshDatabase;

    private string $userAId;

    private string $personAId;

    private string $userBId;

    private string $personBId;

    private string $tenantAId;

    private string $tenantBId;

    private string $inactiveTenantId;

    private string $inactiveMembershipTenantId;

    private string $otherUserTenantId;

    private string $membershipAId;

    private string $membershipBId;

    private string $inactiveMembershipId;

    private string $inactiveTenantMembershipId;

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
        $this->inactiveTenantId = UuidV7::generate();
        $this->inactiveMembershipTenantId = UuidV7::generate();
        $this->otherUserTenantId = UuidV7::generate();
        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();
        $this->inactiveMembershipId = UuidV7::generate();
        $this->inactiveTenantMembershipId = UuidV7::generate();
        $this->otherUserMembershipId = UuidV7::generate();
        $this->emailA = sprintf(
            'browser-switch-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createFixture();
    }

    public function test_browser_switch_prepares_target_credential_without_exposing_bearer_or_replacing_existing_context(): void
    {
        $this->loginBrowserIdentitySession();

        $this->prepareBrowserMembershipCredential(
            $this->membershipAId,
            $this->tenantAId,
            'Browser Switch Tenant A',
        );

        $beforeState = $this->browserAuthState();
        $sourceBearer = $beforeState['membership_credentials'][$this->membershipAId] ?? null;

        $this->assertIsString($sourceBearer);

        $response = $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $this->membershipBId,
            ),
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'membership_id' => $this->membershipBId,
                    'tenant_id' => $this->tenantBId,
                    'tenant_name' => 'Browser Switch Tenant B',
                ],
            ]);

        $afterState = $this->browserAuthState();
        $credentials = $afterState['membership_credentials'] ?? null;

        $this->assertIsArray($credentials);
        $this->assertSame(
            $sourceBearer,
            $credentials[$this->membershipAId] ?? null,
            'Switching must preserve the source Membership credential for another tab.',
        );

        $targetBearer = $credentials[$this->membershipBId] ?? null;

        $this->assertIsString($targetBearer);
        $this->assertNotSame('', trim($targetBearer));
        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $targetBearer,
            $response->getContent(),
        );

        $claims = $this->app
            ->make(TokenManagerInterface::class)
            ->validateAndExtract($targetBearer);

        $this->assertIsArray($claims);
        $this->assertSame(
            'membership',
            $claims['credential_type'] ?? null,
        );
        $this->assertSame(
            $this->userAId,
            $claims['user_id'] ?? null,
        );
        $this->assertSame(
            $this->tenantBId,
            $claims['tenant_id'] ?? null,
        );
        $this->assertSame(
            $this->membershipBId,
            $claims['membership_id'] ?? null,
        );

        $this->assertBrowserContextAvailable(
            $this->membershipAId,
            $this->tenantAId,
        );
        $this->assertBrowserContextAvailable(
            $this->membershipBId,
            $this->tenantBId,
        );

        $this->assertNull(session('active_membership_id'));
        $this->assertNull(session('active_tenant_id'));
    }

    public function test_browser_switch_requires_authenticated_browser_session(): void
    {
        $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $this->membershipBId,
            ),
        )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                'message' => 'Authenticated browser session is required.',
            ]);
    }

    public function test_browser_switch_rejects_invalid_target_membership_identifier(): void
    {
        $this->loginBrowserIdentitySession();

        $this->postJson(
            '/api/v1/browser/user/memberships/not-a-uuid/switch',
        )
            ->assertUnprocessable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'INVALID_BROWSER_MEMBERSHIP_ID',
                'message' => 'Browser membership identifier is invalid.',
            ]);
    }

    public function test_browser_authorization_header_cannot_switch_as_another_user(): void
    {
        $this->loginBrowserIdentitySession();

        $otherUserBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueMembershipToken(
                $this->userBId,
                $this->otherUserTenantId,
                $this->otherUserMembershipId,
            );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$otherUserBearer,
            )
            ->postJson(
                sprintf(
                    '/api/v1/browser/user/memberships/%s/switch',
                    $this->otherUserMembershipId,
                ),
            )
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'MEMBERSHIP_SWITCH_DENIED',
                'message' => 'Requested membership is not available for this user.',
            ]);

        $state = $this->browserAuthState();
        $credentials = $state['membership_credentials'] ?? null;

        $this->assertIsArray($credentials);
        $this->assertArrayNotHasKey(
            $this->otherUserMembershipId,
            $credentials,
        );
    }

    public function test_browser_switch_rejects_inactive_membership_and_inactive_tenant(): void
    {
        $this->loginBrowserIdentitySession();

        foreach (
            [
                $this->inactiveMembershipId,
                $this->inactiveTenantMembershipId,
            ] as $targetMembershipId
        ) {
            $this->postJson(
                sprintf(
                    '/api/v1/browser/user/memberships/%s/switch',
                    $targetMembershipId,
                ),
            )
                ->assertForbidden()
                ->assertExactJson([
                    'status' => 'error',
                    'code' => 'MEMBERSHIP_SWITCH_DENIED',
                    'message' => 'Requested membership is not available for this user.',
                ]);
        }
    }

    public function test_browser_switch_fails_closed_when_target_credential_cannot_be_persisted(): void
    {
        $credentialVault = $this->createMock(
            BrowserSessionCredentialVaultInterface::class,
        );

        $credentialVault
            ->expects($this->once())
            ->method('userId')
            ->willReturn($this->userAId);

        $credentialVault
            ->expects($this->once())
            ->method('storeMembershipCredential')
            ->with(
                $this->membershipBId,
                $this->callback(
                    static fn (mixed $credential): bool => is_string($credential) && trim($credential) !== '',
                ),
            )
            ->willThrowException(
                new RuntimeException(
                    'internal-vault-storage-secret',
                ),
            );

        $this->app->instance(
            BrowserSessionCredentialVaultInterface::class,
            $credentialVault,
        );

        $response = $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $this->membershipBId,
            ),
        );

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_UNAVAILABLE',
                'message' => 'Unable to update the secure browser session.',
            ]);

        $this->assertStringNotContainsString(
            'internal-vault-storage-secret',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );
    }

    public function test_browser_switch_route_uses_web_boundary_and_session_blocking(): void
    {
        $route = Route::getRoutes()->getByName(
            'api.v1.browser.user.memberships.switch',
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'web',
            $route->gatherMiddleware(),
        );
        $this->assertSame(10, $route->locksFor());
        $this->assertSame(10, $route->waitsFor());
    }

    private function loginBrowserIdentitySession(): void
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
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.context_type',
                'identity',
            )
            ->assertJsonPath(
                'data.user.id',
                $this->userAId,
            );

        $state = $this->browserAuthState();

        $this->assertSame(
            $this->userAId,
            $state['user_id'] ?? null,
        );

        $this->assertSame(
            [],
            $state['membership_credentials'] ?? null,
            'Fresh Browser login must begin without Membership credentials.',
        );
    }

    private function prepareBrowserMembershipCredential(
        string $membershipId,
        string $tenantId,
        string $tenantName,
    ): void {
        $response = $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $membershipId,
            ),
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'membership_id' => $membershipId,
                    'tenant_id' => $tenantId,
                    'tenant_name' => $tenantName,
                ],
            ]);

        $state = $this->browserAuthState();

        $credentials = $state[
            'membership_credentials'
        ] ?? null;

        $this->assertIsArray(
            $credentials,
        );

        $bearerCredential = $credentials[
            $membershipId
        ] ?? null;

        $this->assertIsString(
            $bearerCredential,
        );

        $this->assertNotSame(
            '',
            trim(
                $bearerCredential,
            ),
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

    /**
     * @return array<string, mixed>
     */
    private function browserAuthState(): array
    {
        $state = $this->app['session']->get(
            'educore.browser_auth',
        );

        $this->assertIsArray($state);

        return $state;
    }

    private function assertBrowserContextAvailable(
        string $membershipId,
        string $tenantId,
    ): void {
        $sessionCookieName = config('session.cookie');

        $this->assertIsString($sessionCookieName);

        $this
            ->withCredentials()
            ->withCookie(
                $sessionCookieName,
                $this->app['session']->getId(),
            )
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $membershipId,
            )
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $membershipId,
            )
            ->assertJsonPath(
                'data.tenant.id',
                $tenantId,
            );
    }

    private function createFixture(): void
    {
        DB::table('persons')->insert([
            [
                'id' => $this->personAId,
                'name' => 'Browser Switch User A',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->personBId,
                'name' => 'Browser Switch User B',
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
                    'browser-switch-other-%s@educore.test',
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
                'Browser Switch Tenant A',
                true,
            ),
            $this->tenantData(
                $this->tenantBId,
                'Browser Switch Tenant B',
                true,
            ),
            $this->tenantData(
                $this->inactiveTenantId,
                'Browser Switch Inactive Tenant',
                false,
            ),
            $this->tenantData(
                $this->inactiveMembershipTenantId,
                'Browser Switch Suspended Membership Tenant',
                true,
            ),
            $this->tenantData(
                $this->otherUserTenantId,
                'Browser Switch Other User Tenant',
                true,
            ),
        ]);

        DB::table('memberships')->insert([
            $this->membershipData(
                $this->membershipAId,
                $this->personAId,
                $this->tenantAId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->membershipBId,
                $this->personAId,
                $this->tenantBId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->inactiveMembershipId,
                $this->personAId,
                $this->inactiveMembershipTenantId,
                'SUSPENDED',
            ),
            $this->membershipData(
                $this->inactiveTenantMembershipId,
                $this->personAId,
                $this->inactiveTenantId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->otherUserMembershipId,
                $this->personBId,
                $this->otherUserTenantId,
                'ACTIVE',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantData(
        string $tenantId,
        string $name,
        bool $active,
    ): array {
        return [
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'browser-switch-%s',
                Str::lower(Str::random(10)),
            ),
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipData(
        string $membershipId,
        string $personId,
        string $tenantId,
        string $status,
    ): array {
        return [
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
