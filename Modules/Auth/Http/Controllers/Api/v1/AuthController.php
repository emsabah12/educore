<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;

final class AuthController extends Controller
{
    private AuthenticationRepositoryInterface $authRepository;

    private TokenManagerInterface $tokenManager;

    private AuditTrailServiceInterface $auditTrail;

    public function __construct(
        AuthenticationRepositoryInterface $authRepository,
        TokenManagerInterface $tokenManager,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->authRepository = $authRepository;
        $this->tokenManager = $tokenManager;
        $this->auditTrail = $auditTrail;
    }

    /**
     * Login stateless untuk Mobile/API.
     *
     * Authentication hanya menerbitkan token berdasarkan:
     * - User identity
     * - Tenant context
     * - Membership context
     *
     * Authorization role tidak dimasukkan ke token.
     */
    public function loginToken(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'min:6',
            ],
            'tenant_uuid' => [
                'required',
                'uuid',
            ],
        ]);

        $user = $this->authRepository->findByEmailForTenant(
            $credentials['email'],
            $credentials['tenant_uuid']
        );

        if (
            $user === null
            || ! Hash::check(
                $credentials['password'],
                (string) $user['password']
            )
        ) {
            $this->auditTrail->log(
                'auth.login_failed',
                sprintf(
                    'Gagal login via token untuk email: %s',
                    $credentials['email']
                ),
                $credentials['tenant_uuid'],
                $user['id'] ?? null,
                [
                    'channel' => 'mobile_api',
                    'email' => $credentials['email'],
                ]
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid identity credentials.',
            ], 401);
        }

        $accessToken = $this->tokenManager->issueToken(
            $user['id'],
            $user['tenant_id'],
            [
                'membership_id' => $user['membership_id'],
            ]
        );

        $this->auditTrail->log(
            'auth.login_token_success',
            sprintf(
                'User %s sukses login via Mobile Token.',
                $user['name']
            ),
            $user['tenant_id'],
            $user['id'],
            [
                'channel' => 'mobile_api',
                'membership_id' => $user['membership_id'],
            ]
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => 7200,
                'context' => [
                    'user_id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'membership_id' => $user['membership_id'],
                    'tenant_id' => $user['tenant_id'],
                ],
            ],
        ], 200);
    }

    /**
     * Login web session.
     *
     * Role tidak disimpan di cookie/token authentication.
     * Authorization akan di-resolve secara terpisah oleh Authorization layer.
     */
    public function loginSession(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'min:6',
            ],
            'tenant_uuid' => [
                'required',
                'uuid',
            ],
        ]);

        $user = $this->authRepository->findByEmailForTenant(
            $credentials['email'],
            $credentials['tenant_uuid']
        );

        if (
            $user === null
            || ! Hash::check(
                $credentials['password'],
                (string) $user['password']
            )
        ) {
            $this->auditTrail->log(
                'auth.login_failed',
                sprintf(
                    'Gagal login via web session untuk email: %s',
                    $credentials['email']
                ),
                $credentials['tenant_uuid'],
                $user['id'] ?? null,
                [
                    'channel' => 'web_dashboard',
                    'email' => $credentials['email'],
                ]
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid identity credentials.',
            ], 401);
        }

        $secureToken = $this->tokenManager->issueToken(
            $user['id'],
            $user['tenant_id'],
            [
                'membership_id' => $user['membership_id'],
            ]
        );

        $cookie = cookie(
            'auth_token',
            $secureToken,
            120,
            '/',
            null,
            true,
            true,
            false,
            'Lax'
        );

        $this->auditTrail->log(
            'auth.login_session_success',
            sprintf(
                'User %s sukses login via Web Session.',
                $user['name']
            ),
            $user['tenant_id'],
            $user['id'],
            [
                'channel' => 'web_dashboard',
                'membership_id' => $user['membership_id'],
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Web session established successfully.',
            'data' => [
                'context' => [
                    'user_id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'membership_id' => $user['membership_id'],
                    'tenant_id' => $user['tenant_id'],
                ],
            ],
        ], 200)->withCookie($cookie);
    }

    /**
     * Memutus sesi autentikasi.
     */
    public function logout(Request $request): JsonResponse
    {
        $userId = $request->attributes->get(
            'authenticated_user_id'
        );

        $tenantId = $request->attributes->get(
            'authenticated_tenant_id'
        );

        if ($userId) {
            $this->auditTrail->log(
                'auth.logout',
                'User berhasil keluar dari sistem (Session Revoked).',
                $tenantId,
                $userId,
                [
                    'status' => 'explicit_logout',
                ]
            );
        }

        $request->attributes->remove(
            'authenticated_user_id'
        );

        $request->attributes->remove(
            'authenticated_tenant_id'
        );

        $expiredCookie = cookie()->forget(
            'auth_token',
            '/',
            null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Session cleared and logged out successfully.',
        ], 200)->withCookie($expiredCookie);
    }
}
