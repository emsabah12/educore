<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

final class UseBrowserSessionForCanonicalApi
{
    public const TRANSPORT_ATTRIBUTE = 'educore.browser_session_transport';

    public function __construct(
        private readonly EncryptCookies $encryptCookies,
        private readonly StartSession $startSession,
    ) {}

    /**
     * Conditionally activate Laravel's server-side BrowserSession lifecycle for
     * canonical API requests that actually carry the configured session cookie.
     *
     * Bearer-only API clients bypass both cookie decryption and StartSession, so
     * the canonical API remains stateless unless BrowserSession transport is
     * explicitly present on the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $cookieName = config('session.cookie');

        if (
            ! is_string($cookieName)
            || trim($cookieName) === ''
            || ! $request->cookies->has($cookieName)
        ) {
            return $next($request);
        }

        $request->attributes->set(
            self::TRANSPORT_ATTRIBUTE,
            true,
        );

        /*
         * The BrowserSession cookie was emitted through Laravel's web stack and
         * is encrypted. Reuse the framework middleware instead of duplicating
         * cookie/session internals here. EncryptCookies must wrap StartSession so
         * the incoming session identifier is decrypted before lookup and the
         * outgoing session cookie is encrypted again after the response.
         */
        return $this->encryptCookies->handle(
            $request,
            fn (Request $request): Response => $this->startSession->handle(
                $request,
                $next,
            ),
        );
    }
}
