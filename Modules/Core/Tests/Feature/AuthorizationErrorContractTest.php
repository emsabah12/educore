<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Http\Middleware\CheckTenantPermission;
use Modules\Core\Authorization\Http\Middleware\CheckTenantRole;
use Modules\Core\Authorization\Http\Middleware\RequireGlobalSuperadmin;
use Modules\Core\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuthorizationErrorContractTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Auth::guard()->forgetUser();

        parent::tearDown();
    }

    public function test_tenant_role_middleware_uses_canonical_authentication_error_when_identity_is_missing(): void
    {
        Auth::guard()->forgetUser();

        $authorization = $this->createMock(
            AuthorizationServiceInterface::class,
        );

        $authorization
            ->expects($this->never())
            ->method('hasRole');

        $middleware = new CheckTenantRole(
            $authorization,
        );

        $response = $middleware->handle(
            Request::create(
                '/test/tenant-role',
                'GET',
            ),
            static fn(): Response => response()->json([
                'status' => 'success',
            ]),
            'admin',
        );

        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' =>
                'Unauthenticated. Invalid or missing identity context.',
            ],
            $response->getData(true),
        );
    }

    public function test_tenant_role_denial_uses_canonical_authorization_error(): void
    {
        $user = User::factory()->create([
            'is_superadmin' => false,
        ]);

        Auth::guard()->setUser(
            $user,
        );

        $authorization = $this->createMock(
            AuthorizationServiceInterface::class,
        );

        $authorization
            ->expects($this->once())
            ->method('hasRole')
            ->with('admin')
            ->willReturn(false);

        $middleware = new CheckTenantRole(
            $authorization,
        );

        $response = $middleware->handle(
            Request::create(
                '/test/tenant-role',
                'GET',
            ),
            static fn(): Response => response()->json([
                'status' => 'success',
            ]),
            'admin',
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHORIZATION_DENIED',
                'message' =>
                'You are not allowed to perform this operation.',
            ],
            $response->getData(true),
        );
    }

    public function test_tenant_permission_denial_uses_canonical_authorization_error(): void
    {
        $user = User::factory()->create([
            'is_superadmin' => false,
        ]);

        Auth::guard()->setUser(
            $user,
        );

        $authorization = $this->createMock(
            AuthorizationServiceInterface::class,
        );

        $authorization
            ->expects($this->once())
            ->method('hasPermission')
            ->with('example.permission')
            ->willReturn(false);

        $middleware = new CheckTenantPermission(
            $authorization,
        );

        $response = $middleware->handle(
            Request::create(
                '/test/tenant-permission',
                'GET',
            ),
            static fn(): Response => response()->json([
                'status' => 'success',
            ]),
            'example.permission',
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHORIZATION_DENIED',
                'message' =>
                'You are not allowed to perform this operation.',
            ],
            $response->getData(true),
        );
    }

    public function test_global_superadmin_denial_uses_canonical_authorization_error(): void
    {
        $user = User::factory()->create([
            'status' => 'ACTIVE',
            'is_superadmin' => false,
        ]);

        $this->app
            ->make(AuthFactory::class)
            ->guard()
            ->setUser(
                $user,
            );

        $request = Request::create(
            '/test/global-superadmin',
            'GET',
        );

        $request->attributes->set(
            'authenticated_user_id',
            (string) $user->getAuthIdentifier(),
        );

        $middleware = new RequireGlobalSuperadmin(
            $this->app->make(
                AuthFactory::class,
            ),
        );

        $response = $middleware->handle(
            $request,
            static fn(): Response => response()->json([
                'status' => 'success',
            ]),
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHORIZATION_DENIED',
                'message' =>
                'You are not allowed to perform this operation.',
            ],
            $response->getData(true),
        );
    }
}
