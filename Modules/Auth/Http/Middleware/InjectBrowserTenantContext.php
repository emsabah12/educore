<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Support\Uuid\UuidV7;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InjectBrowserTenantContext
{
    public const HEADER = 'X-EduCore-Membership-Id';

    public function __construct(
        private readonly BrowserSessionCredentialVaultInterface $credentialVault,
        private readonly InjectTenantContext $canonicalTenantContext,
    ) {}

    /**
     * Resolve one tab-local Membership locator against server-side BrowserSession
     * credential custody, then delegate all canonical authentication/Tenant
     * validation to InjectTenantContext.
     *
     * The Membership header is an untrusted locator only. It never becomes an
     * authorization claim and it can never create a Membership credential.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $membershipId = $request->header(self::HEADER);

        if (
            ! is_string($membershipId)
            || trim($membershipId) === ''
        ) {
            return ApiErrorResponse::make(
                code: 'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                message: 'Browser membership context is required.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        $membershipId = trim($membershipId);

        if (! UuidV7::validate($membershipId)) {
            return ApiErrorResponse::make(
                code: 'INVALID_BROWSER_MEMBERSHIP_ID',
                message: 'Browser membership identifier is invalid.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $browserUserId = $this->credentialVault->userId();

            if ($browserUserId === null) {
                return $this->authenticationRequiredResponse();
            }

            $bearerCredential = $this->credentialVault
                ->credentialForMembership($membershipId);
        } catch (Throwable $exception) {
            Log::error(
                'Browser session credential resolution failed.',
                [
                    'membership_id' => $membershipId,
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
            return ApiErrorResponse::make(
                code: 'BROWSER_MEMBERSHIP_CONTEXT_DENIED',
                message: 'Browser membership context is not available in this session.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        $originalAuthorization = $request->headers->get('Authorization');

        /*
         * Browser-provided Authorization input must never become authority for
         * BrowserSession requests. The server-selected canonical credential is
         * the only bearer forwarded into canonical authentication middleware.
         */
        $request->headers->set(
            'Authorization',
            'Bearer '.$bearerCredential,
        );

        try {
            return $this->canonicalTenantContext->handle(
                $request,
                function (Request $request) use (
                    $next,
                    $browserUserId,
                    $membershipId,
                ): Response {
                    return $this->continueWhenCanonicalContextMatches(
                        request: $request,
                        next: $next,
                        browserUserId: $browserUserId,
                        membershipId: $membershipId,
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
    private function continueWhenCanonicalContextMatches(
        Request $request,
        Closure $next,
        string $browserUserId,
        string $membershipId,
    ): Response {
        $authenticatedUserId = $request->attributes->get(
            'authenticated_user_id',
        );

        $authenticatedMembershipId = $request->attributes->get(
            'authenticated_membership_id',
        );

        if (
            ! is_string($authenticatedUserId)
            || ! hash_equals($browserUserId, $authenticatedUserId)
            || ! is_string($authenticatedMembershipId)
            || ! hash_equals($membershipId, $authenticatedMembershipId)
        ) {
            Log::warning(
                'Browser session context did not match canonical authentication context.',
                [
                    'browser_user_id' => $browserUserId,
                    'browser_membership_id' => $membershipId,
                    'authenticated_user_id' => is_string($authenticatedUserId)
                        ? $authenticatedUserId
                        : null,
                    'authenticated_membership_id' => is_string($authenticatedMembershipId)
                        ? $authenticatedMembershipId
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
