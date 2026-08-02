<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use RefreshDatabase;

final class InjectTenantContextTest extends TestCase
{

    /**
     * @return array{
     *     user_id: string,
     *     tenant_id: string
     * }
     */
    private function createCanonicalAuthenticationFixture(): array
    {
        $userId = Str::uuid()->toString();
        $tenantId = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Inject Tenant Context User',
            'email' => sprintf(
                'inject-context-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Inject Tenant Context Tenant',
            'subdomain' => sprintf(
                'inject-context-%s',
                Str::lower(Str::random(10)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ];
    }

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

        $this->app->instance(
            TokenManagerInterface::class,
            $this->tokenManager,
        );
    }

    private function middleware(): InjectTenantContext
    {
        return $this->app->make(
            InjectTenantContext::class,
        );
    }


    /**
     * Token valid dengan user_id dan tenant_id wajib
     * menghasilkan authentication context.
     */
    public function test_valid_token_injects_authenticated_user_and_tenant_context(): void
    {
        $fixture = $this->createCanonicalAuthenticationFixture();

        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('valid-token')
            ->willReturn([
                'user_id' => $fixture['user_id'],
                'tenant_id' => $fixture['tenant_id'],
            ]);

        $request = Request::create(
            '/api/protected',
            'GET',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer valid-token',
            ],
        );

        $middleware = $this->middleware();

        $response = $middleware->handle(
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

        /*
     * Middleware melakukan cleanup setelah request selesai.
     */
        $this->assertNull(
            app(TenantContextInterface::class)
                ->getCurrentTenantId(),
        );

        $this->assertNull(auth()->user());
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

        $middleware = $this->middleware();

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

        $middleware = $this->middleware();

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

        $middleware = $this->middleware();

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
        $fixture = $this->createCanonicalAuthenticationFixture();

        $this->tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('valid-token-with-role')
            ->willReturn([
                'user_id' => $fixture['user_id'],
                'tenant_id' => $fixture['tenant_id'],
                'role' => 'admin',
            ]);

        $request = Request::create(
            '/api/protected',
            'GET',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer valid-token-with-role',
            ],
        );

        $middleware = $this->middleware();

        $response = $middleware->handle(
            $request,
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

        $middleware = $this->middleware();

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
