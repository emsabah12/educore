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
use Illuminate\Support\Facades\Log;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthController extends Controller
{
    private AuthenticationRepositoryInterface $authRepository;

    private TokenManagerInterface $tokenManager;

    private AuditTrailServiceInterface $auditTrail;

    private TokenRevocationStoreInterface $tokenRevocationStore;

    public function __construct(
        AuthenticationRepositoryInterface $authRepository,
        TokenManagerInterface $tokenManager,
        TokenRevocationStoreInterface $tokenRevocationStore,
        AuditTrailServiceInterface $auditTrail
    ) {
        $this->authRepository = $authRepository;
        $this->tokenManager = $tokenManager;
        $this->tokenRevocationStore = $tokenRevocationStore;
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
     * Mencabut bearer token yang sedang digunakan.
     *
     * Endpoint berada di belakang InjectTenantContext sehingga request
     * harus sudah memiliki canonical authenticated user, tenant, dan
     * membership context sebelum revocation dijalankan.
     */
    public function logout(Request $request): JsonResponse
    {
        $userId = $request->attributes->get(
            'authenticated_user_id',
        );

        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        $membershipId = $request->attributes->get(
            'authenticated_membership_id',
        );

        $bearerToken = $request->bearerToken();

        if (
            ! is_string($bearerToken)
            || trim($bearerToken) === ''
            || ! is_string($userId)
            || trim($userId) === ''
        ) {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Authenticated logout context is invalid.',
                ],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        /*
     * Token sudah divalidasi oleh InjectTenantContext sebelum controller
     * berjalan. Validasi ulang di sini diperlukan untuk mendapatkan
     * canonical expires_at yang akan menentukan retention revocation row.
     *
     * Raw bearer token tidak dicatat ke log atau audit.
     */
        $claims = $this->tokenManager->validateAndExtract(
            $bearerToken,
        );

        $expiresAt = is_array($claims)
            ? ($claims['expires_at'] ?? null)
            : null;

        if (! is_int($expiresAt)) {
            Log::warning(
                'Authenticated logout token claims could not be resolved.',
                [
                    'user_id' => $userId,
                    'tenant_id' => is_string($tenantId)
                        ? $tenantId
                        : null,
                ],
            );

            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Unable to resolve logout credential.',
                ],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            $this->tokenRevocationStore->revoke(
                token: $bearerToken,
                expiresAt: $expiresAt,
            );
        } catch (Throwable $exception) {
            /*
         * Logout tidak boleh mengklaim sukses jika server gagal
         * menyimpan revocation state.
         */
            Log::error(
                'Authentication token revocation failed during logout.',
                [
                    'user_id' => $userId,
                    'tenant_id' => is_string($tenantId)
                        ? $tenantId
                        : null,
                    'exception' => $exception::class,
                ],
            );

            $this->auditTrail->log(
                'auth.logout_failed',
                'Logout gagal karena token tidak dapat direvoke.',
                is_string($tenantId)
                    ? $tenantId
                    : null,
                $userId,
                [
                    'status' => 'revocation_failed',
                    'token_revoked' => false,
                    'membership_id' => is_string($membershipId)
                        ? $membershipId
                        : null,
                ],
            );

            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Unable to complete logout securely.',
                    'data' => [
                        'token_revoked' => false,
                    ],
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $this->auditTrail->log(
            'auth.logout',
            'User berhasil keluar dari sistem.',
            is_string($tenantId)
                ? $tenantId
                : null,
            $userId,
            [
                'status' => 'explicit_logout',
                'token_revoked' => true,
                'membership_id' => is_string($membershipId)
                    ? $membershipId
                    : null,
            ],
        );

        /*
     * Middleware tetap menjadi pemilik lifecycle cleanup utama.
     * Removal di sini memastikan controller juga tidak mempertahankan
     * authenticated attributes setelah logout acknowledgement dibuat.
     */
        $request->attributes->remove(
            'authenticated_user_id',
        );

        $request->attributes->remove(
            'authenticated_tenant_id',
        );

        $request->attributes->remove(
            'authenticated_membership_id',
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Logout completed successfully.',
            'data' => [
                'token_revoked' => true,
            ],
        ], Response::HTTP_OK);
    }
}
