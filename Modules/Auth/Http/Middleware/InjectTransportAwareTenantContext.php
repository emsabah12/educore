<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InjectTransportAwareTenantContext
{
    public function __construct(
        private readonly InjectBrowserTenantContext $browserTenantContext,
        private readonly InjectTenantContext $canonicalTenantContext,
    ) {}

    /**
     * Select authentication transport only; canonical identity, Membership and
     * Tenant validation remains owned by the existing context middleware.
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
            return $this->browserTenantContext->handle(
                $request,
                $next,
            );
        }

        return $this->canonicalTenantContext->handle(
            $request,
            $next,
        );
    }
}
