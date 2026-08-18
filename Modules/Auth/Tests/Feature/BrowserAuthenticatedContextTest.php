<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class BrowserAuthenticatedContextTest extends TestCase
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
            'browser-context-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        $this->createAuthenticationFixture();
    }

    public function test_browser_me_reuses_canonical_context_projection_without_exposing_bearer(): void
    {
        $this->loginBrowserSession();

        $browserAuthState = $this->app['session']->get(
            'educore.browser_auth',
        );

        $this->assertIsArray($browserAuthState);

        $bearerCredential = $browserAuthState['membership_credentials'][$this->membershipId] ?? null;

        $this->assertIsString($bearerCredential);

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipId,
            )
            ->getJson('/api/v1/browser/auth/me');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'user' => [
                        'id' => $this->userId,
                        'email' => $this->email,
                    ],
                    'person' => [
                        'id' => $this->personId,
                        'name' => 'Browser Context User',
                    ],
                    'membership' => [
                        'id' => $this->membershipId,
                        'status' => 'ACTIVE',
                    ],
                    'tenant' => [
                        'id' => $this->tenantId,
                        'name' => 'Browser Context Tenant',
                        'subdomain' => $this->tenantSubdomain(),
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

    public function test_browser_me_requires_authenticated_browser_session(): void
    {
        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipId,
            )
            ->getJson('/api/v1/browser/auth/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                'message' => 'Authenticated browser session is required.',
            ]);
    }

    public function test_browser_me_requires_membership_locator(): void
    {
        $this->loginBrowserSession();

        $this
            ->getJson('/api/v1/browser/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                'message' => 'Browser membership context is required.',
            ]);
    }

    public function test_browser_me_rejects_invalid_membership_locator(): void
    {
        $this->loginBrowserSession();

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                'not-a-uuid',
            )
            ->getJson('/api/v1/browser/auth/me')
            ->assertUnprocessable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'INVALID_BROWSER_MEMBERSHIP_ID',
                'message' => 'Browser membership identifier is invalid.',
            ]);
    }

    public function test_browser_me_does_not_create_context_for_unknown_membership_locator(): void
    {
        $this->loginBrowserSession();

        $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->alternateMembershipId,
            )
            ->getJson('/api/v1/browser/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_MEMBERSHIP_CONTEXT_DENIED',
                'message' => 'Browser membership context is not available in this session.',
            ]);
    }

    public function test_browser_supplied_authorization_header_cannot_override_server_selected_credential(): void
    {
        $this->loginBrowserSession();

        $this
            ->withHeaders([
                InjectBrowserTenantContext::HEADER => $this->membershipId,
                'Authorization' => 'Bearer browser-controlled-forgery',
            ])
            ->getJson('/api/v1/browser/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $this->membershipId,
            );
    }

    public function test_browser_me_fails_closed_when_vault_locator_maps_to_different_canonical_membership(): void
    {
        $mismatchedBearer = $this->app
            ->make(TokenManagerInterface::class)
            ->issueToken(
                $this->userId,
                $this->alternateTenantId,
                [
                    'membership_id' => $this->alternateMembershipId,
                ],
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
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->membershipId,
            )
            ->getJson('/api/v1/browser/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'BROWSER_SESSION_CONTEXT_MISMATCH',
                'message' => 'Browser session context does not match canonical authentication context.',
            ]);
    }

    private function loginBrowserSession(): void
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'email' => $this->email,
                'password' => 'secret123',
                'tenant_uuid' => $this->tenantId,
            ],
        )->assertOk();
    }

    private function createAuthenticationFixture(): void
    {
        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Browser Context User',
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
            'name' => 'Browser Context Tenant',
            'subdomain' => $this->tenantSubdomain(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $this->alternateTenantId,
            'name' => 'Browser Context Alternate Tenant',
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
        return 'browser-context';
    }

    private function alternateTenantSubdomain(): string
    {
        return 'browser-context-alt';
    }
}
