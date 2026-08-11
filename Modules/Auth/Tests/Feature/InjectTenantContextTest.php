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

final class InjectTenantContextTest extends TestCase
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

    public function test_valid_token_injects_canonical_user_membership_and_tenant_context(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture();

        $this->expectTokenClaims(
            'valid-token',
            $fixture,
        );

        $request = $this->bearerRequest('valid-token');

        $response = $this->middleware()->handle(
            $request,
            function (Request $request) use ($fixture): Response {
                $this->assertSame(
                    $fixture['user_id'],
                    auth()->id(),
                );
                $this->assertSame(
                    $fixture['user_id'],
                    $request->attributes->get('authenticated_user_id'),
                );
                $this->assertSame(
                    $fixture['tenant_id'],
                    $request->attributes->get('authenticated_tenant_id'),
                );
                $this->assertSame(
                    $fixture['membership_id'],
                    $request->attributes->get('authenticated_membership_id'),
                );
                $this->assertSame(
                    $fixture['tenant_id'],
                    app(TenantContextInterface::class)
                        ->getCurrentTenantId(),
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

        $this->assertNull(auth()->user());
        $this->assertNull(
            app(TenantContextInterface::class)
                ->getCurrentTenantId(),
        );
        $this->assertNull(
            $request->attributes->get('authenticated_membership_id'),
        );
    }

    public function test_membership_owned_by_another_person_is_rejected(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture();
        $otherUser = User::factory()->create();

        $forgedMembership = Membership::query()->create([
            'person_id' => $otherUser->person_id,
            'tenant_id' => $fixture['tenant_id'],
            'status' => 'ACTIVE',
        ]);

        $claims = $fixture;
        $claims['membership_id'] = (string) $forgedMembership->getKey();

        $this->expectTokenClaims(
            'forged-membership-token',
            $claims,
        );

        $nextWasCalled = false;

        $response = $this->middleware()->handle(
            $this->bearerRequest('forged-membership-token'),
            static function () use (&$nextWasCalled): Response {
                $nextWasCalled = true;

                return response()->json([
                    'status' => 'success',
                ]);
            },
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
        $this->assertFalse($nextWasCalled);
        $this->assertNull(auth()->user());
        $this->assertNull(
            app(TenantContextInterface::class)
                ->getCurrentTenantId(),
        );
    }

    public function test_membership_from_another_tenant_is_rejected(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture();
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Tenant',
            'subdomain' => 'other-' . strtolower(fake()->lexify('????????')),
            'is_active' => true,
        ]);

        $otherMembership = Membership::query()->create([
            'person_id' => $fixture['person_id'],
            'tenant_id' => $otherTenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $claims = $fixture;
        $claims['membership_id'] = (string) $otherMembership->getKey();

        $this->expectTokenClaims(
            'cross-tenant-membership-token',
            $claims,
        );

        $response = $this->middleware()->handle(
            $this->bearerRequest('cross-tenant-membership-token'),
            static fn(): Response => response()->json([
                'status' => 'success',
            ]),
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
    }

    public function test_suspended_user_is_rejected(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture(
            userStatus: 'SUSPENDED',
        );

        $this->expectTokenClaims(
            'suspended-user-token',
            $fixture,
        );

        $response = $this->middleware()->handle(
            $this->bearerRequest('suspended-user-token'),
            static fn(): Response => response()->json([
                'status' => 'success',
            ]),
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
    }

    public function test_inactive_tenant_is_rejected(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture(
            tenantActive: false,
        );

        $this->expectTokenClaims(
            'inactive-tenant-token',
            $fixture,
        );

        $response = $this->middleware()->handle(
            $this->bearerRequest('inactive-tenant-token'),
            static fn(): Response => response()->json([
                'status' => 'success',
            ]),
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
    }

    public function test_missing_or_invalid_token_is_rejected(): void
    {
        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('invalid-token')
            ->willReturn(null);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $this->middleware()->handle(
                $this->bearerRequest('invalid-token'),
                static fn(): Response => response()->json([]),
            )->getStatusCode(),
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $this->middleware()->handle(
                Request::create('/api/protected', 'GET'),
                static fn(): Response => response()->json([]),
            )->getStatusCode(),
        );
    }

    public function test_missing_membership_claim_is_rejected(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture();

        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('missing-membership-token')
            ->willReturn([
                'user_id' => $fixture['user_id'],
                'tenant_id' => $fixture['tenant_id'],
            ]);

        $response = $this->middleware()->handle(
            $this->bearerRequest('missing-membership-token'),
            static fn(): Response => response()->json([]),
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
    }

    public function test_malformed_tenant_or_membership_claim_is_rejected(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture();

        $this->tokenManager
            ->expects($this->exactly(2))
            ->method('validateAndExtract')
            ->willReturnOnConsecutiveCalls(
                [
                    'user_id' => $fixture['user_id'],
                    'tenant_id' => 'not-a-uuid',
                    'membership_id' => $fixture['membership_id'],
                ],
                [
                    'user_id' => $fixture['user_id'],
                    'tenant_id' => $fixture['tenant_id'],
                    'membership_id' => 'not-a-uuid',
                ],
            );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $this->middleware()->handle(
                $this->bearerRequest('bad-tenant-token'),
                static fn(): Response => response()->json([]),
            )->getStatusCode(),
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $this->middleware()->handle(
                $this->bearerRequest('bad-membership-token'),
                static fn(): Response => response()->json([]),
            )->getStatusCode(),
        );
    }

    public function test_role_claim_is_not_injected_into_request_context(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture();
        $claims = $fixture;
        $claims['role'] = 'admin';

        $this->expectTokenClaims(
            'role-claim-token',
            $claims,
        );

        $response = $this->middleware()->handle(
            $this->bearerRequest('role-claim-token'),
            function (Request $request): Response {
                $this->assertFalse(
                    $request->attributes->has('role'),
                );
                $this->assertFalse(
                    $request->attributes->has('authenticated_role'),
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
    }

    /**
     * @return array{
     *     user_id: string,
     *     person_id: string,
     *     tenant_id: string,
     *     membership_id: string
     * }
     */
    private function createCanonicalAuthenticationFixture(
        string $userStatus = 'ACTIVE',
        bool $tenantActive = true,
    ): array {
        $user = User::factory()->create([
            'status' => $userStatus,
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Inject Tenant Context Tenant',
            'subdomain' => 'inject-' . strtolower(fake()->lexify('????????')),
            'is_active' => $tenantActive,
        ]);

        $membership = Membership::query()->create([
            'person_id' => $user->person_id,
            'tenant_id' => $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        return [
            'user_id' => (string) $user->getKey(),
            'person_id' => (string) $user->person_id,
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

    private function bearerRequest(string $token): Request
    {
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
