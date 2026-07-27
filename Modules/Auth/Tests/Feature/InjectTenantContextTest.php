<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Http\Request;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class InjectTenantContextTest extends TestCase
{
    /**
     * TokenManager mock yang digunakan untuk mengisolasi
     * middleware dari implementasi token sebenarnya.
     *
     * @var TokenManagerInterface&MockObject
     */
    private TokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenManager = $this->createMock(
            TokenManagerInterface::class
        );
    }

    /**
     * Token valid dengan user_id dan tenant_id wajib
     * menghasilkan authentication context.
     */
    public function test_valid_token_injects_authenticated_user_and_tenant_context(): void
    {
        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('valid-token')
            ->willReturn([
                'user_id' => 'user-uuid-123',
                'tenant_id' => 'tenant-uuid-456',
            ]);

        $request = Request::create(
            '/v1/test',
            'GET'
        );

        $request->headers->set(
            'Authorization',
            'Bearer valid-token'
        );

        $middleware = new InjectTenantContext(
            $this->tokenManager
        );

        $response = $middleware->handle(
            $request,
            function (Request $request): Response {
                $this->assertSame(
                    'user-uuid-123',
                    $request->attributes->get(
                        'authenticated_user_id'
                    )
                );

                $this->assertSame(
                    'tenant-uuid-456',
                    $request->attributes->get(
                        'authenticated_tenant_id'
                    )
                );

                return response()->json([
                    'status' => 'success',
                ]);
            }
        );

        $this->assertSame(
            200,
            $response->getStatusCode()
        );
    }

    /**
     * Token invalid wajib ditolak.
     */
    public function test_invalid_token_is_rejected(): void
    {
        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('invalid-token')
            ->willReturn(null);

        $request = Request::create(
            '/v1/test',
            'GET'
        );

        $request->headers->set(
            'Authorization',
            'Bearer invalid-token'
        );

        $middleware = new InjectTenantContext(
            $this->tokenManager
        );

        $response = $middleware->handle(
            $request,
            function (): Response {
                return response()->json([
                    'status' => 'success',
                ]);
            }
        );

        $this->assertSame(
            403,
            $response->getStatusCode()
        );

        $responseData = json_decode(
            $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            'error',
            $responseData['status']
        );
    }

    /**
     * Token tanpa user_id wajib ditolak.
     */
    public function test_token_without_user_id_is_rejected(): void
    {
        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('missing-user-token')
            ->willReturn([
                'tenant_id' => 'tenant-uuid-456',
            ]);

        $request = Request::create(
            '/v1/test',
            'GET'
        );

        $request->headers->set(
            'Authorization',
            'Bearer missing-user-token'
        );

        $middleware = new InjectTenantContext(
            $this->tokenManager
        );

        $response = $middleware->handle(
            $request,
            function (): Response {
                return response()->json([
                    'status' => 'success',
                ]);
            }
        );

        $this->assertSame(
            403,
            $response->getStatusCode()
        );
    }

    /**
     * Token tanpa tenant_id wajib ditolak.
     */
    public function test_token_without_tenant_id_is_rejected(): void
    {
        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('missing-tenant-token')
            ->willReturn([
                'user_id' => 'user-uuid-123',
            ]);

        $request = Request::create(
            '/v1/test',
            'GET'
        );

        $request->headers->set(
            'Authorization',
            'Bearer missing-tenant-token'
        );

        $middleware = new InjectTenantContext(
            $this->tokenManager
        );

        $response = $middleware->handle(
            $request,
            function (): Response {
                return response()->json([
                    'status' => 'success',
                ]);
            }
        );

        $this->assertSame(
            403,
            $response->getStatusCode()
        );
    }

    /**
     * Role yang mungkin terdapat di dalam token tidak boleh
     * diinjeksi sebagai authenticated_role.
     *
     * Authentication context hanya berisi identity.
     */
    public function test_role_claim_is_not_injected_into_request_context(): void
    {
        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('token-with-role')
            ->willReturn([
                'user_id' => 'user-uuid-123',
                'tenant_id' => 'tenant-uuid-456',
                'role' => 'SUPERADMIN',
            ]);

        $request = Request::create(
            '/v1/test',
            'GET'
        );

        $request->headers->set(
            'Authorization',
            'Bearer token-with-role'
        );

        $middleware = new InjectTenantContext(
            $this->tokenManager
        );

        $response = $middleware->handle(
            $request,
            function (Request $request): Response {
                $this->assertNull(
                    $request->attributes->get(
                        'authenticated_role'
                    )
                );

                return response()->json([
                    'status' => 'success',
                ]);
            }
        );

        $this->assertSame(
            200,
            $response->getStatusCode()
        );
    }

    /**
     * Request tanpa Bearer token wajib ditolak.
     */
    public function test_request_without_bearer_token_is_rejected(): void
    {
        $this->tokenManager
            ->expects($this->never())
            ->method('validateAndExtract');

        $request = Request::create(
            '/v1/test',
            'GET'
        );

        $middleware = new InjectTenantContext(
            $this->tokenManager
        );

        $response = $middleware->handle(
            $request,
            function (): Response {
                return response()->json([
                    'status' => 'success',
                ]);
            }
        );

        $this->assertSame(
            403,
            $response->getStatusCode()
        );
    }
}
