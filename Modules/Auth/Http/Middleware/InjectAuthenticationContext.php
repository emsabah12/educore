<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Modules\Core\Contracts\Auth\TokenManagerInterface;
use Modules\Core\Contracts\Auth\AuthenticationRepositoryInterface;
use Exception;

final class InjectAuthenticationContext
{
    private TokenManagerInterface $tokenManager;
    private AuthenticationRepositoryInterface $authRepository;

    /**
     * Dependency Injection otomatis berbasis Platform Contracts Kernel Core.
     */
    public function __construct(
        TokenManagerInterface $tokenManager,
        AuthenticationRepositoryInterface $authRepository
    ) {
        $this->tokenManager = $tokenManager;
        $this->authRepository = $authRepository;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing or malformed Authorization Bearer token.'
            ], 401);
        }

        $token = substr($authorization, 7);

        try {
            // 1. Validasi token dan ekstrak claims via TokenManager Platform Service
            $claims = $this->tokenManager->validateAndExtract($token);

            $userUuid   = $claims['sub'] ?? null;
            $tenantUuid = $claims['tenant_uuid'] ?? null;

            if (!$userUuid || !$tenantUuid) {
                throw new Exception('Token claims are missing critical identity information.');
            }

            // 2. Validasi integritas data pengguna terhadap isolasi context repositori
            $user = $this->authRepository->findByUserUuid($userUuid);
            if (!$user || $user['tenant_uuid'] !== $tenantUuid) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cross-tenant access detected or user context invalid.'
                ], 403);
            }

            // 3. Bind Context secara global ke dalam Service Container (Safe Isolation)
            app()->singleton('current_tenant_uuid', fn() => $tenantUuid);
            app()->singleton('current_user_uuid', fn() => $userUuid);
            app()->singleton('current_auth_user', fn() => $user);

            // Menyisipkan ke internal request attribute untuk kemudahan akses lokal controller
            $request->attributes->set('tenant_uuid', $tenantUuid);
            $request->attributes->set('user_uuid', $userUuid);
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Authentication failed: ' . $e->getMessage()
            ], 401);
        }

        return $next($request);
    }
}
