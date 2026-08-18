<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\Services\AuthenticationCredentialIssuer;
use Modules\Auth\Http\Requests\LoginTokenRequest;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationCredentialIssuer $credentialIssuer,
        private readonly TokenManagerInterface $tokenManager,
        private readonly TokenRevocationStoreInterface $tokenRevocationStore,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

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
    public function loginToken(
        LoginTokenRequest $request,
    ): JsonResponse {
        /**
         * @var array{
         *     email: string,
         *     password: string,
         *     tenant_uuid: string
         * } $credentials
         */
        $credentials = $request->validated();

        $issuedCredential = $this->credentialIssuer->issue(
            email: $credentials['email'],
            password: $credentials['password'],
            tenantUuid: $credentials['tenant_uuid'],
            channel: AuthenticationChannel::MOBILE_API,
        );

        if ($issuedCredential === null) {
            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_FAILED',
                message: 'Invalid authentication credentials.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        $this->auditTrail->log(
            'auth.login_token_success',
            sprintf(
                'User %s sukses login via Mobile Token.',
                $issuedCredential->name,
            ),
            $issuedCredential->tenantId,
            $issuedCredential->userId,
            [
                'channel' => AuthenticationChannel::MOBILE_API->value,
                'membership_id' => $issuedCredential->membershipId,
            ],
        );

        return response()->json(
            [
                'status' => 'success',
                'data' => [
                    'access_token' => $issuedCredential->bearerCredential,
                    'token_type' => 'Bearer',
                    'expires_in' => $issuedCredential->expiresInSeconds,
                    'context' => [
                        'user_id' => $issuedCredential->userId,
                        'name' => $issuedCredential->name,
                        'email' => $issuedCredential->email,
                        'membership_id' => $issuedCredential->membershipId,
                        'tenant_id' => $issuedCredential->tenantId,
                    ],
                ],
            ],
            Response::HTTP_OK,
        );
    }

    /**
     * Mencabut bearer token yang sedang digunakan.
     *
     * Endpoint berada di belakang InjectTenantContext sehingga request
     * harus sudah memiliki canonical authenticated user, tenant, dan
     * membership context sebelum revocation dijalankan.
     */
    public function logout(
        Request $request,
    ): JsonResponse {
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
            return $this->authenticationRequiredResponse();
        }

        /*
         * Token sudah divalidasi oleh InjectTenantContext sebelum
         * controller berjalan.
         *
         * Validasi ulang diperlukan untuk memperoleh canonical
         * expires_at yang menentukan retention revocation row.
         *
         * Raw bearer token tidak dicatat ke log atau audit.
         */
        $claims = $this->tokenManager
            ->validateAndExtract(
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

            return $this->authenticationRequiredResponse();
        }

        try {
            $this->tokenRevocationStore->revoke(
                token: $bearerToken,
                expiresAt: $expiresAt,
            );
        } catch (Throwable $exception) {
            /*
             * Logout tidak boleh mengklaim sukses apabila revocation
             * state gagal disimpan.
             *
             * Detail storage/infrastructure hanya dikirim ke server
             * logging, bukan ke public API response.
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

            return ApiErrorResponse::make(
                code: 'LOGOUT_UNAVAILABLE',
                message: 'Unable to complete logout securely.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
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
         *
         * Removal di sini memastikan controller tidak mempertahankan
         * authenticated attributes setelah logout acknowledgement.
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

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Logout completed successfully.',
                'data' => [
                    'token_revoked' => true,
                ],
            ],
            Response::HTTP_OK,
        );
    }

    private function authenticationRequiredResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_REQUIRED',
            message: 'Unauthenticated. Invalid or missing identity context.',
            status: Response::HTTP_UNAUTHORIZED,
        );
    }
}
