<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Http\Request;
use Modules\Auth\Http\Controllers\Api\v1\AuthController;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuthControllerErrorContractTest extends TestCase
{
    public function test_logout_defensive_guard_uses_canonical_authentication_error(): void
    {
        $controller = $this->app->make(
            AuthController::class,
        );

        $request = Request::create(
            '/api/v1/auth/logout',
            'POST',
        );

        $response = $controller->logout(
            $request,
        );

        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Unauthenticated. Invalid or missing identity context.',
            ],
            $response->getData(true),
        );
    }

    public function test_logout_revocation_failure_uses_canonical_operational_error(): void
    {
        $expiresAt = time() + 3600;

        $tokenManager = $this->createMock(
            TokenManagerInterface::class,
        );

        $tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('opaque-token')
            ->willReturn([
                'expires_at' => $expiresAt,
            ]);

        $revocationStore = $this->createMock(
            TokenRevocationStoreInterface::class,
        );

        $revocationStore
            ->expects($this->once())
            ->method('revoke')
            ->with(
                'opaque-token',
                $expiresAt,
            )
            ->willThrowException(
                new RuntimeException(
                    'internal-revocation-secret',
                ),
            );

        $auditTrail = $this->createMock(
            AuditTrailServiceInterface::class,
        );

        $auditTrail
            ->expects($this->once())
            ->method('log');

        $controller = new AuthController(
            tokenManager: $tokenManager,
            tokenRevocationStore: $revocationStore,
            auditTrail: $auditTrail,
        );

        $request = Request::create(
            '/api/v1/auth/logout',
            'POST',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer opaque-token',
            ],
        );

        $request->attributes->set(
            'authenticated_user_id',
            'test-user-id',
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            'test-tenant-id',
        );

        $request->attributes->set(
            'authenticated_membership_id',
            'test-membership-id',
        );

        $response = $controller->logout(
            $request,
        );

        $this->assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'LOGOUT_UNAVAILABLE',
                'message' => 'Unable to complete logout securely.',
            ],
            $response->getData(true),
        );

        $this->assertStringNotContainsString(
            'internal-revocation-secret',
            $response->getContent(),
        );
    }
}
