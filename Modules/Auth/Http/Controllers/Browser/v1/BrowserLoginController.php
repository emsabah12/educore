<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Browser\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\Services\GlobalAuthenticationService;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Auth\Http\Requests\LoginTokenRequest;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class BrowserLoginController
{
    public function __construct(
        private GlobalAuthenticationService $authenticationService,
        private BrowserSessionCredentialVaultInterface $credentialVault,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    public function __invoke(
        LoginTokenRequest $request,
    ): JsonResponse {
        /**
         * @var array{
         *     identifier: string,
         *     password: string
         * } $credentials
         */
        $credentials = $request->validated();

        $identity = $this->authenticationService
            ->authenticate(
                identifier: $credentials['identifier'],
                password: $credentials['password'],
                channel: AuthenticationChannel::BROWSER_SESSION,
            );

        if ($identity === null) {
            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_FAILED',
                message: 'Invalid authentication credentials.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            /*
             * Prevent session fixation before authenticated BrowserSession
             * identity is established.
             *
             * Regeneration intentionally preserves unrelated session data
             * while replacing the pre-authentication session identifier.
             */
            $request->session()->regenerate(
                true,
            );

            /*
             * Fresh login is identity-only.
             *
             * This operation deliberately clears any Membership credentials
             * left from an earlier browser authentication, including state
             * owned by this same User.
             */
            $this->credentialVault
                ->establishFreshIdentity(
                    $identity->userId,
                );
        } catch (Throwable $exception) {
            $this->clearCredentialVaultAfterFailedLogin();

            Log::error(
                'Browser session establishment failed after global credential verification.',
                [
                    'user_id' => $identity->userId,
                    'exception' => $exception::class,
                ],
            );

            $this->auditTrail->log(
                'auth.login_browser_failed',
                'Browser login failed while establishing secure global identity session.',
                null,
                $identity->userId,
                [
                    'channel' =>
                        AuthenticationChannel::BROWSER_SESSION->value,
                    'status' =>
                        'session_establishment_failed',
                    'context_type' =>
                        'identity',
                ],
            );

            return ApiErrorResponse::make(
                code: 'BROWSER_SESSION_UNAVAILABLE',
                message: 'Unable to establish a secure browser session.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        /*
         * Successful Browser authentication is global Identity Context.
         *
         * Tenant and Membership selection happen later through canonical
         * Membership discovery/switch boundaries.
         */
        $this->auditTrail->log(
            'auth.login_browser_success',
            'Global browser identity login succeeded.',
            null,
            $identity->userId,
            [
                'channel' =>
                    AuthenticationChannel::BROWSER_SESSION->value,
                'context_type' =>
                    'identity',
            ],
        );

        /*
         * No bearer credential is returned to browser JavaScript.
         *
         * Browser authentication authority lives in the hardened
         * server-side BrowserSession.
         */
        return response()->json(
            [
                'status' => 'success',
                'data' => [
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

    private function clearCredentialVaultAfterFailedLogin(): void
    {
        try {
            $this->credentialVault->clear();
        } catch (Throwable $cleanupException) {
            Log::critical(
                'Browser credential vault cleanup failed after login failure.',
                [
                    'exception' =>
                        $cleanupException::class,
                ],
            );
        }
    }
}
