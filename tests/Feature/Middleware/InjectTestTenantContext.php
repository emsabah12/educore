<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

final class InjectTestTenantContext
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $tenantId = $request->header('X-Tenant-ID');

        if (! is_string($tenantId) || trim($tenantId) === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden: Authentication context is incomplete.',
            ], Response::HTTP_FORBIDDEN);
        }

        $tenant = Tenant::query()->find(trim($tenantId));

        if ($tenant === null || ! $tenant->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden: Authentication context is incomplete.',
            ], Response::HTTP_FORBIDDEN);
        }

        /*
         * Canonical runtime tenant context.
         */
        $this->tenantContext->setCurrentTenant($tenant);

        /*
         * Legacy request attributes
         * (dipertahankan selama masa transisi).
         */
        $request->attributes->set(
            'authenticated_user_id',
            (string) $user->getAuthIdentifier(),
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            $tenant->id,
        );

        return $next($request);
    }
}
