<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Application\Services\AuthenticatedIdentityResolver;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

final class InjectTenantContext
{
    public function __construct(
        private readonly AuthenticatedIdentityResolver $identityResolver,
        private readonly AuthFactory $auth,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    /**
     * Membangun canonical authentication dan tenant context
     * untuk lifecycle request saat ini.
     *
     * Alur:
     *
     * Bearer token
     *   ↓
     * Active canonical user
     *   ↓
     * Laravel Auth Guard
     *   ↓
     * Active Tenant
     *   ↓
     * TenantContext
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken)) {
            return $this->contextErrorResponse();
        }

        $identity = $this->identityResolver->resolve(
            $bearerToken,
        );

        if ($identity === null) {
            return $this->contextErrorResponse();
        }

        $tenantId = $identity->stringClaim(
            'tenant_id',
        );

        if ($tenantId === null || ! Str::isUuid($tenantId)) {
            return $this->contextErrorResponse();
        }

        /*
         * Tenant wajib ditemukan dan aktif sebelum runtime tenant boundary
         * dibentuk.
         */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || ! (bool) $tenant->is_active) {
            return $this->contextErrorResponse();
        }

        $guard = $this->auth->guard();
        $guard->setUser($identity->user);

        $this->tenantContext->setCurrentTenant($tenant);

        $request->attributes->set(
            'authenticated_user_id',
            $identity->userId,
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            $tenantId,
        );

        try {
            return $next($request);
        } finally {
            /*
             * Hindari state bocor pada Octane, long-running worker,
             * parallel testing, dan request berikutnya.
             */
            $this->tenantContext->clear();
            $guard->forgetUser();

            $request->attributes->remove(
                'authenticated_user_id',
            );

            $request->attributes->remove(
                'authenticated_tenant_id',
            );
        }
    }

    private function contextErrorResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Authentication context missing or invalid.',
        ], Response::HTTP_FORBIDDEN);
    }
}
