<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Contracts\Auth\AuthenticationRepositoryInterface;
use Modules\Core\Contracts\Auth\TokenManagerInterface;
use Modules\Core\Contracts\Auth\AuditTrailServiceInterface;

final class AuthController extends Controller
{
    private AuthenticationRepositoryInterface $authRepository;
    private TokenManagerInterface $tokenManager;
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection melalui Constructor untuk menyuntikkan AuditTrail Engine (SOLID).
     */
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
     * Mengelola permintaan login token stateless untuk klien Mobile/API + Audit Logging.
     */
    public function loginToken(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'       => ['required', 'email', 'max:255'],
            'password'    => ['required', 'string', 'min:6'],
            'tenant_uuid' => ['required', 'string', 'max:36'],
        ]);

        $user = $this->authRepository->findByEmailForTenant(
            $credentials['email'],
            $credentials['tenant_uuid']
        );

        // Kasus Gagal Login: Catat kejadian kegagalan ke Audit Trail
        if (! $user || ! Hash::check($credentials['password'], $user['password'])) {
            $this->auditTrail->log(
                'auth.login_failed',
                sprintf('Gagal login via token untuk email: %s', $credentials['email']),
                $credentials['tenant_uuid'],
                $user['id'] ?? null,
                ['channel' => 'mobile_api', 'email' => $credentials['email']]
            );

            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized. Invalid identity credentials.',
            ], 401);
        }

        $accessToken = $this->tokenManager->issueToken(
            $user['id'],
            $user['tenant_id'],
            [
                'membership_id' => $user['membership_id'],
                'role'          => $user['role'],
            ]
        );

        // Kasus Sukses Login via Token: Catat ke Audit Trail
        $this->auditTrail->log(
            'auth.login_token_success',
            sprintf('User %s [%s] sukses login via Mobile Token.', $user['name'], $user['role']),
            $user['tenant_id'],
            $user['id'],
            ['channel' => 'mobile_api']
        );

        return response()->json([
            'status' => 'success',
            'data'   => [
                'access_token' => $accessToken,
                'token_type'   => 'Bearer',
                'expires_in'   => 7200,
                'context'      => [
                    'user_id'   => $user['id'],
                    'name'      => $user['name'],
                    'email'     => $user['email'],
                    'role'      => $user['role'],
                    'tenant_id' => $user['tenant_id'],
                ],
            ],
        ], 200);
    }

    /**
     * Menerbitkan Sesi Terenkripsi via HTTP-Only Cookie untuk Klien Web + Audit Logging.
     */
    public function loginSession(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'       => ['required', 'email', 'max:255'],
            'password'    => ['required', 'string', 'min:6'],
            'tenant_uuid' => ['required', 'string', 'max:36'],
        ]);

        $user = $this->authRepository->findByEmailForTenant(
            $credentials['email'],
            $credentials['tenant_uuid']
        );

        // Kasus Gagal Login Web Session: Catat ke Audit Trail
        if (! $user || ! Hash::check($credentials['password'], $user['password'])) {
            $this->auditTrail->log(
                'auth.login_failed',
                sprintf('Gagal login via web session untuk email: %s', $credentials['email']),
                $credentials['tenant_uuid'],
                $user['id'] ?? null,
                ['channel' => 'web_dashboard', 'email' => $credentials['email']]
            );

            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized. Invalid identity credentials.',
            ], 401);
        }

        $secureToken = $this->tokenManager->issueToken(
            $user['id'],
            $user['tenant_id'],
            [
                'membership_id' => $user['membership_id'],
                'role'          => $user['role'],
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

        // Kasus Sukses Login Web Session: Catat ke Audit Trail
        $this->auditTrail->log(
            'auth.login_session_success',
            sprintf('User %s [%s] sukses login via Web Session.', $user['name'], $user['role']),
            $user['tenant_id'],
            $user['id'],
            ['channel' => 'web_dashboard']
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Web session established successfully.',
            'data' => [
                'context' => [
                    'user_id'   => $user['id'],
                    'name'      => $user['name'],
                    'role'      => $user['role'],
                    'tenant_id' => $user['tenant_id']
                ]
            ]
        ], 200)->withCookie($cookie);
    }

    /**
     * Memutus Sesi Otentikasi Terpadu (Dual-Channel) + Audit Logging.
     */
    public function logout(Request $request): JsonResponse
    {
        // Ekstrak data penanda identitas sebelum dibersihkan dari atribut request
        $userId = $request->attributes->get('authenticated_user_id');
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        // Catat aksi keluar sistem ke Audit Trail jika data context tersedia
        if ($userId) {
            $this->auditTrail->log(
                'auth.logout',
                'User berhasil keluar dari sistem (Session Revoked).',
                $tenantId,
                $userId,
                ['status' => 'explicit_logout']
            );
        }

        // Evakuasi dan bersihkan data internal request attribute
        $request->attributes->remove('authenticated_user_id');
        $request->attributes->remove('authenticated_tenant_id');

        // Susun Kuki Mati untuk memotong hak akses di level browser web
        $expiredCookie = cookie()->forget('auth_token', '/', null);

        return response()->json([
            'status'  => 'success',
            'message' => 'Session cleared and logged out successfully.',
        ], 200)->withCookie($expiredCookie);
    }
}
