<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InjectTransportAwareAuthenticatedUser
{
    public function __construct(
        private readonly InjectBrowserAuthenticatedUser $browserAuthenticatedUser,
        private readonly InjectAuthenticatedUser $canonicalAuthenticatedUser,
    ) {}

    /**
     * Select authentication transport for user-only canonical resources.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        if (
            $request->attributes->get(
                UseBrowserSessionForCanonicalApi::TRANSPORT_ATTRIBUTE,
            ) === true
        ) {
            return $this->browserAuthenticatedUser->handle(
                $request,
                $next,
            );
        }

        return $this->canonicalAuthenticatedUser->handle(
            $request,
            $next,
        );
    }
}
