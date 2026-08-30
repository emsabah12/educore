<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\Services\GlobalAuthenticationService;
use Modules\Auth\Application\Services\IdentityCredentialIssuer;
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
        private readonly TokenManagerInterface $tokenManager,
        private readonly TokenRevocationStoreInterface $tokenRevocationStore,
        private readonly AuditTrailServiceInterface $auditTrail,
    ) {}

    /**
     * Authenticate a global User and issue an identity-scoped bearer.
     *
     * Tenant, Membership, role, and permission context are deliberately not
     * established during global login.
     */
    public function loginToken(
        LoginTokenRequest $request,
        GlobalAuthenticationService $authenticationService,
        IdentityCredentialIssuer $identityCredentialIssuer,
    ): JsonResponse {
        /**
         * @var array{
         *     identifier: string,
         *     password: string
         * } $credentials
         */
        $credentials = $request->validated();

        $identity = $authenticationService->authenticate(
            identifier: $credentials['identifier'],
            password: $credentials['password'],
            channel: AuthenticationChannel::MOBILE_API,
        );

        if ($identity === null) {
            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_FAILED',
                message: 'Invalid authentication credentials.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        $issuedCredential = $identityCredentialIssuer->issue(
            $identity->userId,
        );

        /*
         * Successful global login audit deliberately has no Tenant or
         * Membership scope.
         *
         * Do not include raw password, bearer credential, or password hash.
         */
        $this->auditTrail->log(
            'auth.login_token_success',
            'Global identity login succeeded via Mobile/API.',
            null,
            $identity->userId,
            [
                'channel' =>
                    AuthenticationChannel::MOBILE_API->value,
                'context_type' => 'identity',
            ],
        );

        return response()->json(
            [
                'status' => 'success',
                'data' => [
                    'access_token' =>
                        $issuedCredential->bearerCredential,
                    'token_type' => 'Bearer',
                    'expires_in' =>
                        $issuedCredential->expiresInSeconds,
                    'context_type' => 'identity',
                    'user' => [
                        'id' => $identity->userId,
                        'name' => $identity->name,
                        'email' => $identity->email,
                        'username' => $identity->username,
                    ],
                    'platform' => [
                        'is_superadmin' =>
                            $identity->isSuperadmin,
                    ],
                ],
            ],
            Response::HTTP_OK,
        );
    }

    /**
     * Mencabut bearer token yang sedang digunakan.
     *
     * Logout middleware/context migration is a separate controlled step.
     * Existing verified Tenant/Membership logout behavior is preserved here.
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
         * Token sudah divalidasi oleh middleware sebelum controller berjalan.
         *
         * Validasi ulang diperlukan untuk memperoleh canonical expires_at
         * yang menentukan retention revocation row.
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
