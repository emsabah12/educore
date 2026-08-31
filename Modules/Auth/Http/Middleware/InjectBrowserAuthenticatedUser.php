<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InjectBrowserAuthenticatedUser
{
    public function __construct(
        private readonly BrowserSessionCredentialVaultInterface $credentialVault,
        private readonly ActiveUserResolverInterface $activeUserResolver,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Resolve canonical global User identity directly from BrowserSession.
     *
     * Browser authentication is identity-first and therefore must not require
     * a Membership credential, Tenant context, or browser-provided bearer.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        try {
            $browserUserId = $this->credentialVault
                ->userId();
        } catch (Throwable $exception) {
            Log::error(
                'Browser session user identity resolution failed.',
                [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'exception' => $exception::class,
                ],
            );

            return $this->sessionUnavailableResponse();
        }

        if ($browserUserId === null) {
            return $this->authenticationRequiredResponse();
        }

        try {
            $user = $this->activeUserResolver
                ->findActiveById(
                    $browserUserId,
                );
        } catch (Throwable $exception) {
            Log::error(
                'Canonical BrowserSession user lookup failed.',
                [
                    'browser_user_id' => $browserUserId,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'exception' => $exception::class,
                ],
            );

            return $this->sessionUnavailableResponse();
        }

        if ($user === null) {
            /*
             * A BrowserSession owner that no longer resolves to an ACTIVE
             * canonical User is no longer authenticated.
             */
            return $this->authenticationRequiredResponse();
        }

        /*
         * Browser-provided Authorization is deliberately ignored here.
         *
         * Authentication authority for Browser transport is the hardened
         * server-side BrowserSession identity.
         */
        $guard = $this->auth->guard();

        $guard->setUser(
            $user,
        );

        $request->attributes->set(
            'authenticated_user_id',
            $browserUserId,
        );

        try {
            return $next(
                $request,
            );
        } finally {
            /*
             * Request-local identity must never leak into a subsequent
             * request handled by the same application process.
             */
            $guard->forgetUser();

            $request->attributes->remove(
                'authenticated_user_id',
            );
        }
    }

    private function authenticationRequiredResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
            message: 'Authenticated browser session is required.',
            status: Response::HTTP_UNAUTHORIZED,
        );
    }

    private function sessionUnavailableResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BROWSER_SESSION_UNAVAILABLE',
            message: 'Unable to resolve the secure browser session.',
            status: Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
