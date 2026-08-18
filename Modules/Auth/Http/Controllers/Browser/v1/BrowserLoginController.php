<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Browser\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\Services\AuthenticationCredentialIssuer;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Auth\Http\Requests\LoginTokenRequest;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class BrowserLoginController
{
    public function __construct(
        private AuthenticationCredentialIssuer $credentialIssuer,
        private BrowserSessionCredentialVaultInterface $credentialVault,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    public function __invoke(LoginTokenRequest $request): JsonResponse
    {
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
            channel: AuthenticationChannel::BROWSER_SESSION,
        );

        if ($issuedCredential === null) {
            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_FAILED',
                message: 'Invalid authentication credentials.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            /*
             * Destroy the pre-authentication session identifier before the
             * canonical bearer is moved into BrowserSession custody.
             */
            $request->session()->regenerate(true);

            $this->credentialVault->establishForUser(
                $issuedCredential->userId,
            );

            $this->credentialVault->storeMembershipCredential(
                $issuedCredential->membershipId,
                $issuedCredential->bearerCredential,
            );
        } catch (Throwable $exception) {
            $this->clearCredentialVaultAfterFailedLogin();

            Log::error(
                'Browser session establishment failed after credential verification.',
                [
                    'user_id' => $issuedCredential->userId,
                    'tenant_id' => $issuedCredential->tenantId,
                    'membership_id' => $issuedCredential->membershipId,
                    'exception' => $exception::class,
                ],
            );

            $this->auditTrail->log(
                'auth.login_browser_failed',
                'Browser login gagal saat membentuk secure server session.',
                $issuedCredential->tenantId,
                $issuedCredential->userId,
                [
                    'channel' => AuthenticationChannel::BROWSER_SESSION->value,
                    'status' => 'session_establishment_failed',
                    'membership_id' => $issuedCredential->membershipId,
                ],
            );

            return ApiErrorResponse::make(
                code: 'BROWSER_SESSION_UNAVAILABLE',
                message: 'Unable to establish a secure browser session.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $this->auditTrail->log(
            'auth.login_browser_success',
            sprintf(
                'User %s sukses login via Browser Session.',
                $issuedCredential->name,
            ),
            $issuedCredential->tenantId,
            $issuedCredential->userId,
            [
                'channel' => AuthenticationChannel::BROWSER_SESSION->value,
                'membership_id' => $issuedCredential->membershipId,
            ],
        );

        return response()->json(
            [
                'status' => 'success',
                'data' => [
                    'membership_id' => $issuedCredential->membershipId,
                    'tenant_id' => $issuedCredential->tenantId,
                ],
            ],
            Response::HTTP_OK,
        );
    }

    private function clearCredentialVaultAfterFailedLogin(): void
    {
        try {
            $this->credentialVault->clear();
        } catch (Throwable $cleanupException) {
            Log::critical(
                'Browser credential vault cleanup failed after login failure.',
                [
                    'exception' => $cleanupException::class,
                ],
            );
        }
    }
}
