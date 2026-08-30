<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class TenantCredentialTypeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** @var TokenManagerInterface&MockObject */
    private TokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenManager = $this->createMock(
            TokenManagerInterface::class,
        );

        $this->app->instance(
            TokenManagerInterface::class,
            $this->tokenManager,
        );
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        auth()->forgetGuards();

        parent::tearDown();
    }

    public function test_typed_membership_credential_can_establish_membership_context(): void
    {
        $fixture = $this->createCanonicalMembershipFixture();

        $this->expectTokenClaims(
            token: 'typed-membership-token',
            claims: [
                'credential_type' => 'membership',
                'user_id' => $fixture['user_id'],
                'tenant_id' => $fixture['tenant_id'],
                'membership_id' => $fixture['membership_id'],
                'expires_at' => time() + 3600,
            ],
        );

        $request = $this->bearerRequest(
            'typed-membership-token',
        );

        $nextWasCalled = false;

        $response = $this->middleware()->handle(
            $request,
            function (Request $request) use (
                &$nextWasCalled,
                $fixture,
            ): Response {
                $nextWasCalled = true;

                $this->assertSame(
                    $fixture['user_id'],
                    $request->attributes->get(
                        'authenticated_user_id',
                    ),
                );

                $this->assertSame(
                    $fixture['tenant_id'],
                    $request->attributes->get(
                        'authenticated_tenant_id',
                    ),
                );

                $this->assertSame(
                    $fixture['membership_id'],
                    $request->attributes->get(
                        'authenticated_membership_id',
                    ),
                );

                return response()->json([
                    'status' => 'success',
                ]);
            },
        );

        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $this->assertTrue(
            $nextWasCalled,
        );

        $this->assertNull(
            auth()->user(),
        );

        $this->assertNull(
            app(TenantContextInterface::class)
                ->getCurrentTenantId(),
        );
    }

    public function test_identity_credential_cannot_establish_membership_context_even_with_smuggled_context_claims(): void
    {
        $fixture = $this->createCanonicalMembershipFixture();

        /*
         * This payload deliberately simulates defense-in-depth failure above
         * the middleware boundary.
         *
         * Canonical token validation already rejects Identity Credentials with
         * extra claims. InjectTenantContext must nevertheless enforce its own
         * Membership Credential boundary and fail closed if such claims ever
         * reach it.
         */
        $this->expectTokenClaims(
            token: 'identity-with-smuggled-context',
            claims: [
                'credential_type' => 'identity',
                'user_id' => $fixture['user_id'],
                'tenant_id' => $fixture['tenant_id'],
                'membership_id' => $fixture['membership_id'],
                'expires_at' => time() + 3600,
            ],
        );

        $nextWasCalled = false;

        $response = $this->middleware()->handle(
            $this->bearerRequest(
                'identity-with-smuggled-context',
            ),
            static function () use (
                &$nextWasCalled,
            ): Response {
                $nextWasCalled = true;

                return response()->json([
                    'status' => 'success',
                ]);
            },
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
            'Identity Credentials must never establish Membership/Tenant context.',
        );

        $this->assertFalse(
            $nextWasCalled,
            'Tenant middleware must fail before invoking the protected handler.',
        );

        $this->assertSame(
            'AUTHENTICATION_CONTEXT_DENIED',
            $response->getData(true)['code'] ?? null,
        );

        $this->assertNull(
            auth()->user(),
        );

        $this->assertNull(
            app(TenantContextInterface::class)
                ->getCurrentTenantId(),
        );
    }

    /**
     * @return array{
     *     user_id: string,
     *     tenant_id: string,
     *     membership_id: string
     * }
     */
    private function createCanonicalMembershipFixture(): array
    {
        $user = User::factory()->create([
            'status' => 'ACTIVE',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Typed Credential Boundary Tenant',
            'subdomain' => 'typed-' . strtolower(
                fake()->lexify('????????'),
            ),
            'is_active' => true,
        ]);

        $membership = Membership::query()->create([
            'person_id' => $user->person_id,
            'tenant_id' => $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        return [
            'user_id' => (string) $user->getKey(),
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function expectTokenClaims(
        string $token,
        array $claims,
    ): void {
        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with($token)
            ->willReturn($claims);
    }

    private function bearerRequest(
        string $token,
    ): Request {
        return Request::create(
            '/api/protected',
            'GET',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );
    }

    private function middleware(): InjectTenantContext
    {
        return $this->app->make(
            InjectTenantContext::class,
        );
    }
}
