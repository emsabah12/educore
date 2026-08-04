<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Http\Requests\LoginTokenRequest;
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
    public function loginToken(LoginTokenRequest $request): JsonResponse
    {
        /**
         * @var array{
         *     email: string,
         *     password: string,
         *     tenant_uuid: string
         * } $credentials
         */
        $credentials = $request->validated();

        $user = $this->authRepository->findByEmailForTenant(
            $credentials['email'],
            $credentials['tenant_uuid'],
        );

        if (
            $user === null
            || ! Hash::check(
                $credentials['password'],
                (string) $user['password'],
            )
        ) {
            $this->auditTrail->log(
                'auth.login_failed',
                sprintf(
                    'Gagal login via token untuk email: %s',
                    $credentials['email'],
                ),
                $credentials['tenant_uuid'],
                $user['id'] ?? null,
                [
                    'channel' => 'mobile_api',
                    'email' => $credentials['email'],
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid identity credentials.',
            ], 401);
        }

        $accessToken = $this->tokenManager->issueToken(
            (string) $user['id'],
            (string) $user['tenant_id'],
            [
                'membership_id' => (string) $user['membership_id'],
            ],
        );

        $this->auditTrail->log(
            'auth.login_token_success',
            sprintf(
                'User %s sukses login via Mobile Token.',
                (string) $user['name'],
            ),
            (string) $user['tenant_id'],
            (string) $user['id'],
            [
                'channel' => 'mobile_api',
                'membership_id' => (string) $user['membership_id'],
            ],
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->tokenManager->lifetimeInSeconds(),
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
     * Mengakhiri authentication context untuk request saat ini.
     *
     * Token deterministic saat ini bersifat stateless dan belum memiliki
     * server-side revocation storage. Karena itu logout hanya mencatat audit
     * dan membersihkan runtime request attributes.
     */
    public function logout(Request $request): JsonResponse
    {
        $userId = $request->attributes->get(
            'authenticated_user_id',
        );

        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (is_string($userId) && trim($userId) !== '') {
            $this->auditTrail->log(
                'auth.logout',
                'User berhasil keluar dari sistem.',
                is_string($tenantId)
                    ? $tenantId
                    : null,
                $userId,
                [
                    'status' => 'explicit_logout',
                    'token_revoked' => false,
                ],
            );
        }

        $request->attributes->remove(
            'authenticated_user_id',
        );

        $request->attributes->remove(
            'authenticated_tenant_id',
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Logout acknowledged successfully.',
            'data' => [
                'token_revoked' => false,
            ],
        ], 200);
    }
}
