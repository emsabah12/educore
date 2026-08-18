<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionAuthenticationCredentialProviderInterface;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InjectBrowserAuthenticatedUser
{
    public function __construct(
        private readonly BrowserSessionCredentialVaultInterface $credentialVault,
        private readonly BrowserSessionAuthenticationCredentialProviderInterface $credentialProvider,
        private readonly InjectAuthenticatedUser $canonicalAuthenticatedUser,
    ) {}

    /**
     * Prove the BrowserSession owner through the canonical user identity
     * middleware without requiring a Membership locator.
     *
     * Browser-provided Authorization input is never authoritative. One
     * server-held credential is selected through a least-privilege provider and
     * is used only for the duration of canonical identity validation.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        try {
            $browserUserId = $this->credentialVault->userId();

            if ($browserUserId === null) {
                return $this->authenticationRequiredResponse();
            }

            $bearerCredential = $this->credentialProvider
                ->credentialForAuthentication();
        } catch (Throwable $exception) {
            Log::error(
                'Browser session user credential resolution failed.',
                [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'exception' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'BROWSER_SESSION_UNAVAILABLE',
                message: 'Unable to resolve the secure browser session.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if ($bearerCredential === null) {
            return $this->authenticationRequiredResponse();
        }

        $originalAuthorization = $request->headers->get('Authorization');

        $request->headers->set(
            'Authorization',
            'Bearer '.$bearerCredential,
        );

        try {
            return $this->canonicalAuthenticatedUser->handle(
                $request,
                function (Request $request) use (
                    $next,
                    $browserUserId,
                ): Response {
                    return $this->continueWhenCanonicalUserMatches(
                        request: $request,
                        next: $next,
                        browserUserId: $browserUserId,
                    );
                },
            );
        } finally {
            if ($originalAuthorization === null) {
                $request->headers->remove('Authorization');
            } else {
                $request->headers->set(
                    'Authorization',
                    $originalAuthorization,
                );
            }
        }
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function continueWhenCanonicalUserMatches(
        Request $request,
        Closure $next,
        string $browserUserId,
    ): Response {
        $authenticatedUserId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (
            ! is_string($authenticatedUserId)
            || ! hash_equals($browserUserId, $authenticatedUserId)
        ) {
            Log::warning(
                'Browser session user did not match canonical authentication context.',
                [
                    'browser_user_id' => $browserUserId,
                    'authenticated_user_id' => is_string($authenticatedUserId)
                        ? $authenticatedUserId
                        : null,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'BROWSER_SESSION_CONTEXT_MISMATCH',
                message: 'Browser session context does not match canonical authentication context.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }

    private function authenticationRequiredResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
            message: 'Authenticated browser session is required.',
            status: Response::HTTP_UNAUTHORIZED,
        );
    }
}
