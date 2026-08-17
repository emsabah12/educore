<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuthenticationErrorContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerContractRoutes();
    }

    public function test_identity_middleware_uses_canonical_authentication_error(): void
    {
        $this
            ->getJson(
                '/test-auth/error-contract/identity',
            )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' =>
                'Unauthenticated. Invalid or missing identity context.',
            ]);
    }

    public function test_invalid_identity_token_uses_same_stable_error_code(): void
    {
        $this
            ->withToken('invalid-token')
            ->getJson(
                '/test-auth/error-contract/identity',
            )
            ->assertUnauthorized()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_REQUIRED',
            );
    }

    public function test_tenant_context_middleware_uses_canonical_context_error(): void
    {
        $this
            ->getJson(
                '/test-auth/error-contract/tenant',
            )
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' =>
                'AUTHENTICATION_CONTEXT_DENIED',
                'message' =>
                'Authentication context missing or invalid.',
            ]);
    }

    private function registerContractRoutes(): void
    {
        Route::middleware([
            InjectAuthenticatedUser::class,
        ])->get(
            '/test-auth/error-contract/identity',
            static fn(Request $request): array => [
                'status' => 'success',
                'user_id' => $request->user()?->getAuthIdentifier(),
            ],
        );

        Route::middleware([
            InjectTenantContext::class,
        ])->get(
            '/test-auth/error-contract/tenant',
            static fn(): array => [
                'status' => 'success',
            ],
        );
    }
}
