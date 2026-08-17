<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Http\Request;
use Modules\Auth\Http\Controllers\Api\v1\AuthenticatedContextController;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuthenticatedContextControllerErrorContractTest extends TestCase
{
    public function test_bootstrap_defensive_guard_uses_canonical_authentication_context_error(): void
    {
        $tenantContext = $this->createMock(
            TenantContextInterface::class,
        );

        $tenantContext
            ->expects($this->once())
            ->method('getCurrentTenant')
            ->willReturn(null);

        $controller =
            new AuthenticatedContextController(
                tenantContext: $tenantContext,
            );

        $request = Request::create(
            '/api/v1/auth/me',
            'GET',
        );

        $response = $controller(
            $request,
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' =>
                'AUTHENTICATION_CONTEXT_DENIED',
                'message' =>
                'Authentication context missing or invalid.',
            ],
            $response->getData(true),
        );
    }
}
